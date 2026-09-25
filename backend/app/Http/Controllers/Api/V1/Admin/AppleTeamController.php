<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AppleTeamStatus;
use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AppleCredential;
use App\Models\AppleTeam;
use App\Models\Certificate;
use App\Models\MembershipYear;
use App\Models\SigningProfile;
use App\Models\TeamAppEligibility;
use App\Models\TeamAssignment;
use App\Services\Apple\AppleException;
use App\Services\Apple\AppleIntegration;
use App\Services\Audit\AuditService;
use App\Services\Quotas\QuotaReconciler;
use App\Services\Quotas\QuotaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Apple team operations (FULL_PLAN §6, §12; IMPLEMENTATION_PLAN P7-BE-01).
 * Admin-only. Teams are records of accounts that already exist at Apple:
 * nothing here creates or rotates Apple accounts.
 */
class AppleTeamController extends Controller
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly QuotaService $quotas,
    ) {}

    public function index(): JsonResponse
    {
        $teams = AppleTeam::query()->with('activeCredential')->orderByDesc('is_primary')->orderBy('name')->get();

        return ApiResponse::ok($teams->map(fn (AppleTeam $team) => $this->present($team))->all(), meta: [
            'pending_assignments' => TeamAssignment::query()->where('status', 'PENDING')->count(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'apple_team_id' => ['required', 'string', 'regex:/^[A-Z0-9]{10}$/', 'unique:apple_teams,apple_team_id'],
            'name' => ['required', 'string', 'max:255'],
            'membership_expires_at' => ['nullable', 'date'],
        ]);

        $team = AppleTeam::create($data + ['status' => AppleTeamStatus::PendingVerification, 'is_primary' => AppleTeam::primary() === null]);
        $this->audit->record('apple_team.created', $team, after: ['apple_team_id' => $team->apple_team_id, 'name' => $team->name]);

        return ApiResponse::ok($this->present($team->refresh()), 201);
    }

    public function update(Request $request, AppleTeam $team): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', Rule::enum(AppleTeamStatus::class)],
            'is_primary' => ['sometimes', 'accepted'],
            'membership_expires_at' => ['sometimes', 'nullable', 'date'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        // FULL_PLAN §12: activate only after a successful connection test.
        if (($data['status'] ?? null) === AppleTeamStatus::Active->value && $team->last_verified_at === null) {
            throw new ApiException(ErrorCode::Conflict, 'Сначала проверьте подключение к App Store Connect.');
        }

        $before = $team->only(['name', 'status', 'is_primary', 'membership_expires_at']);
        DB::transaction(function () use ($team, $data) {
            if (isset($data['is_primary'])) {
                AppleTeam::query()->whereKeyNot($team->id)->update(['is_primary' => false]);
                $team->is_primary = true;
            }
            $team->fill(array_diff_key($data, array_flip(['reason', 'is_primary'])))->save();
        });

        $this->audit->record('apple_team.updated', $team, before: self::plain($before), after: self::plain($team->only(array_keys($before))), reason: $data['reason']);

        return ApiResponse::ok($this->present($team->refresh()));
    }

    /**
     * Records a credential reference. The .p8 key itself is stored on the server
     * with `php artisan apple:store-key`, which prints the reference.
     */
    public function storeCredential(Request $request, AppleTeam $team): JsonResponse
    {
        $data = $request->validate([
            'issuer_id' => ['required', 'string', 'max:64'],
            'key_id' => ['required', 'string', 'regex:/^[A-Z0-9]{10}$/'],
            'vault_reference' => ['required', 'string', 'max:255', 'regex:/^[a-z-]+:[^\s]+$/'],
        ]);

        DB::transaction(function () use ($team, $data) {
            $team->credentials()->where('status', 'ACTIVE')->update(['status' => 'REVOKED']);
            AppleCredential::create($data + ['apple_team_id' => $team->id]);
            $team->forceFill(['last_verified_at' => null])->save();
        });
        $this->audit->record('apple_team.credential_replaced', $team, after: ['key_id' => $data['key_id'], 'issuer_id' => $data['issuer_id']]);

        return ApiResponse::ok($this->present($team->refresh()), 201);
    }

    /**
     * "Test connection" (FULL_PLAN §12).
     */
    public function verify(AppleTeam $team, AppleIntegration $apple): JsonResponse
    {
        try {
            $apple->verifyCredentials($team->load('activeCredential'));
        } catch (AppleException $e) {
            $this->audit->record('apple_team.verification_failed', $team, reason: $e->getMessage());

            throw new ApiException(ErrorCode::AppleUnavailable, 'App Store Connect не принял ключ: '.$e->getMessage(), status: 422);
        }

        $team->forceFill(['last_verified_at' => now()])->save();
        $team->activeCredential?->forceFill(['last_verified_at' => now()])->save();
        $this->audit->record('apple_team.verified', $team);

        return ApiResponse::ok($this->present($team->refresh()));
    }

    public function storeMembershipYear(Request $request, AppleTeam $team): JsonResponse
    {
        $data = $request->validate([
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
        ]);
        $starts = Carbon::parse($data['starts_at']);
        $ends = Carbon::parse($data['ends_at']);

        $overlaps = $team->membershipYears()->where('status', 'ACTIVE')->where('starts_at', '<', $ends)->where('ends_at', '>', $starts)->exists();
        if ($overlaps) {
            throw new ApiException(ErrorCode::Conflict, 'Период пересекается с существующим годом членства.');
        }

        $year = MembershipYear::create(['apple_team_id' => $team->id, 'starts_at' => $starts, 'ends_at' => $ends]);
        $team->forceFill(['membership_expires_at' => $team->membershipYears()->max('ends_at')])->save();
        $this->audit->record('apple_team.membership_year_added', $team, after: ['starts_at' => $year->starts_at->toIso8601ZuluString(), 'ends_at' => $year->ends_at->toIso8601ZuluString()]);

        return ApiResponse::ok($this->present($team->refresh()), 201);
    }

    /**
     * Reconcile now (FULL_PLAN §12 "sync membership/quota").
     */
    public function sync(AppleTeam $team, QuotaReconciler $reconciler): JsonResponse
    {
        $summary = $reconciler->run($team);
        $this->audit->record('apple_team.sync_requested', $team, after: $summary);

        return ApiResponse::ok($this->present($team->refresh()) + ['reconciliation' => $summary]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(AppleTeam $team): array
    {
        $year = $team->currentMembershipYear();
        $credential = $team->activeCredential;

        return [
            'id' => $team->public_id,
            'apple_team_id' => $team->apple_team_id,
            'name' => $team->name,
            'status' => $team->status->value,
            'is_primary' => (bool) $team->is_primary,
            'membership_expires_at' => $team->membership_expires_at?->toIso8601ZuluString(),
            'last_verified_at' => $team->last_verified_at?->toIso8601ZuluString(),
            'credential' => $credential ? ['key_id' => $credential->key_id, 'issuer_id' => $credential->issuer_id, 'last_verified_at' => $credential->last_verified_at?->toIso8601ZuluString()] : null,
            'membership_year' => $year ? ['starts_at' => $year->starts_at->toIso8601ZuluString(), 'ends_at' => $year->ends_at->toIso8601ZuluString()] : null,
            'quotas' => $this->quotas->summary($team),
            'eligibilities' => TeamAppEligibility::query()->where(['apple_team_id' => $team->id, 'status' => 'APPROVED'])->pluck('bundle_identifier')->all(),
            'certificates' => Certificate::query()->where('apple_team_id', $team->id)->orderBy('expires_at')->get()->map(fn (Certificate $certificate) => [
                'sha1' => $certificate->sha1_fingerprint,
                'common_name' => $certificate->common_name,
                'status' => $certificate->status,
                'expires_at' => $certificate->expires_at?->toIso8601ZuluString(),
                'on_runner' => $certificate->runner_id !== null,
            ])->all(),
            'profiles' => [
                'active' => SigningProfile::query()->where(['apple_team_id' => $team->id, 'status' => 'ACTIVE'])->count(),
                'expiring_soon' => SigningProfile::query()->where(['apple_team_id' => $team->id, 'status' => 'ACTIVE'])->where('expires_at', '<', now()->addDays(QuotaReconciler::WARN_DAYS))->count(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function plain(array $values): array
    {
        return array_map(fn ($value) => $value instanceof \BackedEnum ? $value->value : ($value instanceof \DateTimeInterface ? $value->format(DATE_ATOM) : $value), $values);
    }
}
