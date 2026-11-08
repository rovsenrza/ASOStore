<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\DeviceRegistrationStatus as Status;
use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Jobs\RegisterDeviceJob;
use App\Models\DeviceRegistration;
use App\Services\Devices\DeviceRegistrationService;
use App\StateMachines\StateMachine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Operator actions on one Apple registration of a device. None of them takes a new Apple slot:
 * enabling only works for a registration that already holds one.
 */
class DeviceRegistrationController extends Controller
{
    public function __construct(private readonly StateMachine $states) {}

    /**
     * Turns a disabled registration back on. It goes through APPLE_PENDING and is checked with
     * Apple right away, so it only becomes ELIGIBLE when Apple really has the device enabled.
     * The wait is not compensated: the operator decided this, not Apple.
     */
    public function enable(Request $request, DeviceRegistration $registration, DeviceRegistrationService $registrations): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        if ($registration->status !== Status::Disabled) {
            throw new ApiException(ErrorCode::Conflict, 'Включить можно только отключённую регистрацию.');
        }
        if ($registration->apple_device_id === null) {
            throw new ApiException(ErrorCode::Conflict, 'У регистрации нет устройства в Apple — используйте «Повторить регистрацию».');
        }

        $this->states->transition($registration, Status::ApplePending, $data['reason'], extra: [
            'status_reason' => 'APPLE_PROCESSING',
            'last_synced_at' => null,
            'delay_assessed_at' => now(),
            'delay_compensation_minutes' => 0,
            'delay_gift_at' => $registration->delay_gift_at ?? now(),
        ]);

        try {
            $registrations->sync($registration);
        } catch (Throwable $exception) {
            // Apple busy: the scheduled sync checks it again within minutes.
            report($exception);
        }

        return ApiResponse::ok(CustomerController::registration($registration->refresh()->load('team')));
    }

    /** Stops a registration from being used (the Apple slot stays taken for the year). */
    public function disable(Request $request, DeviceRegistration $registration): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        if ($registration->status !== Status::Eligible) {
            throw new ApiException(ErrorCode::Conflict, 'Отключить можно только активную (ELIGIBLE) регистрацию.');
        }

        $this->states->transition($registration, Status::Disabled, $data['reason'], extra: ['status_reason' => 'CANCELLED_BY_ADMIN']);

        return ApiResponse::ok(CustomerController::registration($registration->refresh()->load('team')));
    }

    /** Asks Apple again now: polls a pending one, or retries a failed or blocked one. */
    public function retry(Request $request, DeviceRegistration $registration, DeviceRegistrationService $registrations): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        if ($registration->status === Status::ApplePending && $registration->apple_device_id !== null) {
            try {
                $registrations->sync($registration);
            } catch (Throwable $exception) {
                report($exception);
            }
        } elseif (in_array($registration->status, [Status::AppleFailed, Status::QuotaBlocked, Status::NoEligibleTeam, Status::Enrolled, Status::ApplePending], true)) {
            // register() starts from any of these itself (and resumes a pending one without an Apple id).
            app(\App\Services\Audit\AuditService::class)->record('device_registration.retry_requested', $registration, reason: $data['reason'] ?? null);
            RegisterDeviceJob::dispatch($registration->id);
        } else {
            throw new ApiException(ErrorCode::Conflict, 'Для этой регистрации повторять нечего.');
        }

        return ApiResponse::ok(CustomerController::registration($registration->refresh()->load('team')), 202);
    }
}
