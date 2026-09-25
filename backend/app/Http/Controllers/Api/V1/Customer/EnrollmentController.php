<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Services\Audit\Actor;
use App\Services\Audit\AuditService;
use App\Services\Devices\EnrollmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * iOS Profile Service endpoints (IMPLEMENTATION_PLAN §5.5). Neither returns
 * the JSON envelope: the first is a file download, the second answers the
 * device with a redirect that iOS opens in Safari.
 */
class EnrollmentController extends Controller
{
    public function __construct(private readonly EnrollmentService $enrollment) {}

    public function profile(Request $request): Response
    {
        $profile = $this->enrollment->issueProfile(
            $request->user(),
            url('/api/v1/devices/enrollment/callback'),
            $request->ip(),
        );

        return response($profile['content'], 200, [
            'Content-Type' => 'application/x-apple-aspen-config',
            'Content-Disposition' => 'attachment; filename="storefront-enrollment.mobileconfig"',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function callback(Request $request, AuditService $audit): RedirectResponse
    {
        $token = (string) $request->query('challenge', '');

        try {
            $device = $this->enrollment->complete($token, $request->getContent());
        } catch (ApiException $e) {
            $audit->record('device.enrollment_failed', after: ['code' => $e->errorCode->value] + $e->details, actor: Actor::system('enrollment'));

            return redirect('/activate.html?'.http_build_query(['enrollment_error' => $e->errorCode->value]), 301);
        }

        return redirect('/activate.html?'.http_build_query(['enrolled' => $device->public_id]), 301);
    }
}
