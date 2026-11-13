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
use App\Services\Devices\UdidHasher;
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
    /** Admins (devices.reveal-udid) see full UDIDs in lists; others the last four characters. */
    private bool $showUdid = false;

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(DeviceRegistrationStatus::class)],
            'family' => ['nullable', Rule::enum(DeviceFamily::class)],
            'team' => ['nullable', 'string', 'max:20'],
            // Paid places for an iPhone that never connected (no UDID) are listed only on request.
            'connection' => ['nullable', 'in:connected,pending'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Device::query()->with(['user', 'latestRegistration.team'])->latest('id');
        ($filters['connection'] ?? 'connected') === 'pending' ? $query->pending() : $query->enrolled();
        $this->showUdid = $request->user()?->can('devices.reveal-udid') ?? false;

        if (filled($filters['q'] ?? null)) {
            $term = $filters['q'];
            $compact = preg_replace('/[^0-9A-Za-z]/', '', $term) ?? '';
            $query->where(fn (Builder $where) => $where
                ->where('udid_hint', strtoupper(substr($compact, -4)))
                ->when(strlen($compact) >= 20, fn (Builder $full) => $full->orWhere('udid_hash', app(UdidHasher::class)->hash($term)))
                ->orWhereHas('user', fn (Builder $user) => $user->where('email', 'like', '%'.addcslashes($term, '%_\\').'%')));
        }
        if (filled($filters['status'] ?? null)) {
            $query->whereHas('latestRegistration', fn (Builder $registration) => $registration->where('status', $filters['status']));
        }
        if (filled($filters['team'] ?? null)) {
            $query->whereHas('latestRegistration.team', fn (Builder $team) => $team->where('apple_team_id', strtoupper($filters['team'])));
        }
        if (filled($filters['family'] ?? null)) {
            $query->where('device_family', $filters['family']);
        }

        $page = $query->paginate($filters['per_page'] ?? 25);

        return ApiResponse::paginated($page, array_map(fn (Device $device) => $this->present($device), $page->items()));
    }

    public function show(Request $request, Device $device): JsonResponse
    {
        $this->showUdid = $request->user()?->can('devices.reveal-udid') ?? false;
        $device->load(['user', 'latestRegistration.team', 'registrations' => fn ($query) => $query->with('team')->latest('id')]);

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
            'user' => ['id' => $device->user->public_id, 'number' => $device->user->id, 'email' => $device->user->email],
            'udid_hint' => $device->maskedUdid(),
            'udid' => $this->showUdid ? $device->udid_encrypted : null,
            'connected' => $device->isEnrolled(),
            'product' => $device->product,
            'family' => $device->device_family->value,
            'os_version' => $device->os_version,
            'enrolled_at' => $device->enrolled_at?->toIso8601ZuluString(),
            'storefront_claimed_at' => $device->storefront_claimed_at?->toIso8601ZuluString(),
            'registration' => $registration ? [
                'id' => $registration->public_id,
                'status' => $registration->status->value,
                'reason' => $registration->status_reason,
                'team' => $registration->team?->apple_team_id,
                'created_at' => $registration->created_at?->toIso8601ZuluString(),
                'eligible_at' => $registration->eligible_at?->toIso8601ZuluString(),
                'waited_hours' => $registration->created_at ? round($registration->created_at->diffInMinutes($registration->eligible_at ?? now(), true) / 60, 1) : null,
                'updated_at' => $registration->updated_at?->toIso8601ZuluString(),
            ] : null,
        ];
    }
}
