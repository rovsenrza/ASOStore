<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Enums\ErrorCode;
use App\Enums\InstallationStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\CatalogApp;
use App\Models\Device;
use App\Models\Installation;
use App\Services\Devices\CurrentDevice;
use App\Services\Installations\InstallationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Preparation and installation for the calling device (IMPLEMENTATION_PLAN P6-BE-03, P6-BE-04).
 */
class InstallationController extends Controller
{
    public function __construct(
        private readonly InstallationService $installations,
        private readonly CurrentDevice $devices,
    ) {}

    public function prepare(Request $request, string $app): JsonResponse
    {
        $model = CatalogApp::query()->visibleToCustomers()->where('public_id', strtolower($app))->firstOrFail();

        return $this->start($request, $model);
    }

    /**
     * The portal's «Установить Storefront» button: the native Storefront is itself
     * a catalog artifact installed through the same flow (IMPLEMENTATION_PLAN §5.6).
     */
    public function installStorefront(Request $request): JsonResponse
    {
        $storefront = CatalogApp::query()->where('is_storefront', true)->latest('id')->first()
            ?? throw new ApiException(ErrorCode::ArtifactNotInstallable);

        return $this->start($request, $storefront);
    }

    public function show(Request $request, string $installation): JsonResponse
    {
        return ApiResponse::ok($this->installations->present($this->owned($request, $installation)));
    }

    public function authorize(Request $request, string $installation): JsonResponse
    {
        $model = $this->owned($request, $installation);

        return ApiResponse::ok($this->installations->authorize($model, $this->device($request), $request->ip()));
    }

    /**
     * Library: the latest installation per app on this device, with the states
     * the server can observe (IMPLEMENTATION_PLAN G13).
     */
    public function library(Request $request): JsonResponse
    {
        $device = $this->device($request);
        $latest = Installation::query()
            ->with(['app', 'artifact', 'signedBuild'])
            ->where('device_id', $device->id)
            ->whereIn('id', Installation::query()->selectRaw('max(id)')->where('device_id', $device->id)->groupBy('app_id'))
            ->latest('updated_at')
            ->get();

        return ApiResponse::ok($latest->map(fn (Installation $installation) => $this->installations->present($installation))->all());
    }

    private function start(Request $request, CatalogApp $app): JsonResponse
    {
        $installation = $this->installations->prepare($request->user(), $this->device($request), $app);

        return ApiResponse::ok(
            $this->installations->present($installation->refresh()),
            $installation->status === InstallationStatus::Preparing ? 202 : 200,
        );
    }

    private function owned(Request $request, string $publicId): Installation
    {
        return Installation::query()
            ->where('public_id', strtolower($publicId))
            ->where('device_id', $this->device($request)->id)
            ->firstOrFail();
    }

    private function device(Request $request): Device
    {
        return $this->devices->resolve($request) ?? throw new ApiException(ErrorCode::DeviceNotEligible);
    }
}
