<?php

namespace App\Services\Activation;

use App\Enums\ActivationCodeStatus;
use App\Enums\ErrorCode;
use App\Enums\SubscriptionStatus;
use App\Exceptions\ApiException;
use App\Models\ActivationCode;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Audit\Actor;
use App\Services\Audit\AuditService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Activation codes: 16 Crockford base32 characters (80 random bits), shown
 * once as XXXX-XXXX-XXXX-XXXX and stored only as SHA-256. The entropy makes a
 * keyed hash unnecessary; redemption is also rate-limited.
 */
class ActivationCodeService
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    private const LENGTH = 16;

    public function __construct(private readonly AuditService $audit) {}

    /**
     * @return array{batch_id: string, codes: list<string>}
     */
    public function generate(
        User $creator,
        int $count,
        string $plan,
        ?int $durationDays,
        ?CarbonInterface $expiresAt,
        ?string $note,
    ): array {
        return DB::transaction(function () use ($creator, $count, $plan, $durationDays, $expiresAt, $note) {
            $batchId = strtolower((string) Str::ulid());
            $codes = [];

            for ($i = 0; $i < $count; $i++) {
                $code = $this->randomCode();
                ActivationCode::create([
                    'batch_id' => $batchId,
                    'code_hash' => self::hash($code),
                    'code_hint' => substr($code, -4),
                    'status' => ActivationCodeStatus::Issued,
                    'plan' => $plan,
                    'duration_days' => $durationDays,
                    'expires_at' => $expiresAt,
                    'note' => $note,
                    'created_by' => $creator->id,
                ]);
                $codes[] = implode('-', str_split($code, 4));
            }

            // Codes themselves never reach the audit log.
            $this->audit->record('activation_code.batch_created', after: [
                'batch_id' => $batchId,
                'count' => $count,
                'plan' => $plan,
                'duration_days' => $durationDays,
                'expires_at' => $expiresAt?->toIso8601ZuluString(),
            ], actor: Actor::user($creator));

            return ['batch_id' => $batchId, 'codes' => $codes];
        });
    }

    public function redeem(User $user, string $input): Subscription
    {
        $code = self::normalize($input);

        if (strlen($code) !== self::LENGTH) {
            throw new ApiException(ErrorCode::ActivationInvalid);
        }

        return DB::transaction(function () use ($user, $code) {
            $record = ActivationCode::query()->where('code_hash', self::hash($code))->lockForUpdate()->first();

            if ($record === null) {
                throw new ApiException(ErrorCode::ActivationInvalid);
            }

            match ($record->effectiveStatus()) {
                ActivationCodeStatus::Redeemed => throw new ApiException(ErrorCode::ActivationAlreadyUsed),
                ActivationCodeStatus::Revoked, ActivationCodeStatus::Expired => throw new ApiException(ErrorCode::ActivationInvalid),
                ActivationCodeStatus::Issued => null,
            };

            $record->forceFill([
                'status' => ActivationCodeStatus::Redeemed,
                'redeemed_by' => $user->id,
                'redeemed_at' => now(),
            ])->save();

            // A new code extends the current subscription instead of overlapping it.
            $current = $user->activeSubscription()->first();
            $endsAt = match (true) {
                $record->duration_days === null, $current !== null && $current->ends_at === null => null,
                default => ($current->ends_at ?? now())->copy()->addDays($record->duration_days),
            };

            $subscription = Subscription::create([
                'user_id' => $user->id,
                'activation_code_id' => $record->id,
                'plan' => $record->plan,
                'status' => SubscriptionStatus::Active,
                'starts_at' => now(),
                'ends_at' => $endsAt,
            ]);

            $this->audit->record('activation_code.redeemed', $record, after: [
                'subscription_id' => $subscription->public_id,
                'plan' => $subscription->plan,
                'ends_at' => $endsAt?->toIso8601ZuluString(),
            ], actor: Actor::user($user));

            return $subscription;
        });
    }

    public function revoke(ActivationCode $code, User $actor, string $reason): ActivationCode
    {
        return DB::transaction(function () use ($code, $actor, $reason) {
            $locked = ActivationCode::query()->whereKey($code->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== ActivationCodeStatus::Issued) {
                throw new ApiException(ErrorCode::Conflict, details: ['status' => $locked->effectiveStatus()->value]);
            }

            $locked->forceFill([
                'status' => ActivationCodeStatus::Revoked,
                'revoked_by' => $actor->id,
                'revoked_at' => now(),
            ])->save();

            $this->audit->record('activation_code.revoked', $locked, before: ['status' => 'ISSUED'], after: ['status' => 'REVOKED'], reason: $reason, actor: Actor::user($actor));

            return $locked;
        });
    }

    /**
     * Accepts lower case, spaces, dashes and the usual Crockford look-alikes.
     */
    public static function normalize(string $input): string
    {
        $code = strtoupper(preg_replace('/[\s\-]+/', '', $input) ?? '');

        return strtr($code, ['O' => '0', 'I' => '1', 'L' => '1']);
    }

    public static function hash(string $normalizedCode): string
    {
        return hash('sha256', $normalizedCode);
    }

    private function randomCode(): string
    {
        $code = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $code;
    }
}
