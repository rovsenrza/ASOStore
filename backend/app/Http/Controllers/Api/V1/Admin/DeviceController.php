<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\DeviceFamily;
use App\Enums\DeviceRegistrationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Http\Responses\ApiResponse;
use App\Jobs\RegisterDeviceJob;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\DeviceRegistration;
use App\Services\Audit\AuditService;
use App\Services\Devices\DeviceRegistrationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Operator view of devices (IMPLEMENTATION_PLAN P3-BE-06). UDIDs are masked;
 * revealing one needs devices.reveal-udid, a reason, and is audited.
 */
class DeviceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(DeviceRegistrationStatus::class)],
            'family' => ['nullable', Rule::enum(DeviceFamily::class)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Device::query()->with(['user', 'latestRegistration'])->latest('id');

        if (filled($filters['q'] ?? null)) {
            $term = $filters['q'];
            $query->where(fn (Builder $where) => $where
                ->where('udid_hint', strtoupper(substr(preg_replace('/[^0-9A-Za-z]/', '', $term) ?? '', -4)))
                ->orWhereHas('user', fn (Builder $user) => $user->where('email', 'like', '%'.addcslashes($term, '%_\\').'%')));
        }
        if (filled($filters['status'] ?? null)) {
            $query->whereHas('latestRegistration', fn (Builder $registration) => $registration->where('status', $filters['status']));
        }
        if (filled($filters['family'] ?? null)) {
            $query->where('device_family', $filters['family']);
        }

        $page = $query->paginate($filters['per_page'] ?? 25);

        return ApiResponse::paginated($page, array_map(fn (Device $device) => $this->present($device), $page->items()));
    }

    public function show(Request $request, Device $device): JsonResponse
    {
        $device->load(['user', 'latestRegistration', 'registrations' => fn ($query) => $query->with('team')->latest('id')]);

        $history = AuditLog::query()
            ->where('subject_type', 'device')->where('subject_id', $device->public_id)
            ->orWhere(fn (Builder $registrations) => $registrations
                ->where('subject_type', 'device_registration')
                ->whereIn('subject_id', $device->registrations->pluck('public_id')))
            ->latest('id')
            ->limit(30)
            ->get();

        return ApiResponse::ok($this->present($device) + [
            'registrations' => $device->registrations->map(fn (DeviceRegistration $registration) => [
                'id' => $registration->public_id,
                'team' => $registration->team->apple_team_id,
                'status' => $registration->status->value,
                'reason' => $registration->status_reason,
                'apple_device_id' => $registration->apple_device_id,
                'attempts' => $registration->attempts,
                'registered_at' => $registration->registered_at?->toIso8601ZuluString(),
                'eligible_at' => $registration->eligible_at?->toIso8601ZuluString(),
                'updated_at' => $registration->updated_at?->toIso8601ZuluString(),
            ])->all(),
            'history' => $history->map(fn (AuditLog $entry) => (new AuditLogResource($entry))->resolve($request))->all(),
        ]);
    }

    /**
     * Retries the Apple registration now, e.g. after a failure or once a team is connected.
     */
    public function sync(Device $device, DeviceRegistrationService $registrations, AuditService $audit): JsonResponse
    {
        $registration = $device->latestRegistration ?? $registrations->request($device);
        $audit->record('device.sync_requested', $device);

        if ($registration !== null) {
            RegisterDeviceJob::dispatch($registration->id);
        }

        return ApiResponse::ok(['queued' => $registration !== null], 202);
    }

    public function revealUdid(Request $request, Device $device, AuditService $audit): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $audit->record('device.udid_revealed', $device, after: ['udid_hint' => $device->maskedUdid()], reason: $data['reason']);

        return ApiResponse::ok(['udid' => $device->udid_encrypted]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Device $device): array
    {
        $registration = $device->latestRegistration;

        return [
            'id' => $device->public_id,
            'user' => ['id' => $device->user->public_id, 'email' => $device->user->email],
            'udid_hint' => $device->maskedUdid(),
            'product' => $device->product,
            'family' => $device->device_family->value,
            'os_version' => $device->os_version,
            'enrolled_at' => $device->enrolled_at?->toIso8601ZuluString(),
            'storefront_claimed_at' => $device->storefront_claimed_at?->toIso8601ZuluString(),
            'registration' => $registration ? [
                'status' => $registration->status->value,
                'reason' => $registration->status_reason,
                'updated_at' => $registration->updated_at?->toIso8601ZuluString(),
            ] : null,
        ];
    }
}
