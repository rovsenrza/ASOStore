<?php

namespace App\Http\Controllers\Api\Worker;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AppleTeam;
use App\Models\Certificate;
use App\Models\PipelineJob;
use App\Models\Runner;
use App\Services\Signing\SigningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Worker API for the macOS signing runner (IMPLEMENTATION_PLAN §5.8, P6-BE-02).
 * Pull model: the runner leases work; the backend never pushes secrets.
 */
class WorkerController extends Controller
{
    public function __construct(private readonly SigningService $signing) {}

    /**
     * Runner health plus the signing identities in its Keychain (metadata only).
     */
    public function heartbeat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'version' => ['nullable', 'string', 'max:32'],
            'identities' => ['nullable', 'array', 'max:50'],
            'identities.*.sha1' => ['required', 'string', 'regex:/^[A-Fa-f0-9]{40}$/'],
            'identities.*.serial_number' => ['required', 'string', 'max:64'],
            'identities.*.team_identifier' => ['required', 'string', 'max:20'],
            'identities.*.common_name' => ['required', 'string', 'max:255'],
            'identities.*.expires_at' => ['nullable', 'date'],
        ]);

        $runner = $this->runner($request);
        $identities = array_values($data['identities'] ?? []);
        $runner->forceFill([
            'version' => $data['version'] ?? null,
            'last_heartbeat_at' => now(),
            'last_ip' => $request->ip(),
            'identities' => $identities,
        ])->save();

        foreach ($identities as $identity) {
            $team = AppleTeam::query()->where('apple_team_id', $identity['team_identifier'])->first();
            if ($team === null) {
                continue;
            }
            $certificate = Certificate::query()->firstOrNew(['sha1_fingerprint' => strtoupper($identity['sha1'])]);
            $certificate->fill([
                'apple_team_id' => $team->id,
                'serial_number' => $identity['serial_number'],
                'common_name' => $identity['common_name'],
                'expires_at' => isset($identity['expires_at']) ? Carbon::parse($identity['expires_at']) : null,
                'runner_id' => $runner->id,
                'last_seen_at' => now(),
            ]);
            if ($certificate->expires_at?->isPast()) {
                $certificate->status = 'EXPIRED';
            }
            $certificate->save();
        }

        return ApiResponse::ok(['runner_id' => $runner->public_id, 'server_time' => now()->toIso8601ZuluString()]);
    }

    public function lease(Request $request): JsonResponse
    {
        $job = $this->signing->lease($this->runner($request));

        // data is null when there is nothing to sign right now.
        return ApiResponse::ok($job);
    }

    public function jobHeartbeat(Request $request, string $job): JsonResponse
    {
        $model = $this->job($job);
        $this->signing->assertOwner($model, $this->runner($request));
        $model->forceFill(['lease_expires_at' => now()->addSeconds(SigningService::LEASE_SECONDS)])->save();

        return ApiResponse::ok(['lease_expires_at' => $model->lease_expires_at?->toIso8601ZuluString()]);
    }

    public function source(Request $request, string $job): StreamedResponse
    {
        $model = $this->job($job);
        $build = $this->signing->assertOwner($model, $this->runner($request));
        $artifact = $build->artifact;

        return Storage::disk($artifact->storage_disk)->download($artifact->storage_path, $artifact->sha256.'.ipa', [
            'Content-Type' => 'application/octet-stream',
            'X-Content-SHA256' => $artifact->sha256,
        ]);
    }

    public function upload(Request $request, string $job): JsonResponse
    {
        $model = $this->job($job);
        $body = $request->getContent(true);
        $stored = $this->signing->storeSignedFile($model, $this->runner($request), $body);

        // The signature covered the declared hash; the streamed bytes must match it.
        if (! hash_equals(strtolower((string) $request->header('X-Content-SHA256')), $stored['sha256'])) {
            throw new ApiException(ErrorCode::UploadCorrupt, details: $stored);
        }

        return ApiResponse::ok($stored, 201);
    }

    public function result(Request $request, string $job): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:succeeded,failed'],
            'sha256' => ['required_if:status,succeeded', 'nullable', 'string', 'regex:/^[a-fA-F0-9]{64}$/'],
            'error_code' => ['nullable', 'string', 'max:64'],
            'error_message' => ['nullable', 'string', 'max:2000'],
            'report' => ['nullable', 'array'],
        ]);

        $build = $this->signing->complete($this->job($job), $this->runner($request), $data);

        return ApiResponse::ok(['signed_build_id' => $build->public_id, 'status' => $build->status->value]);
    }

    private function runner(Request $request): Runner
    {
        $runner = $request->attributes->get('runner');

        return $runner instanceof Runner ? $runner : throw new ApiException(ErrorCode::Unauthenticated);
    }

    private function job(string $publicId): PipelineJob
    {
        return PipelineJob::query()->where('public_id', strtolower($publicId))->firstOrFail();
    }
}
