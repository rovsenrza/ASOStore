<?php

namespace App\Services\Auth;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Notifications\EmailVerificationCodeNotification;
use App\Services\Audit\Actor;
use App\Services\Audit\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Six-digit email codes for accounts registered on the website. Only browser
 * sessions are held back until the address is confirmed; the native app,
 * which signs in with tokens, is not affected.
 */
class EmailVerificationService
{
    public const TTL_MINUTES = 15;

    public const MAX_ATTEMPTS = 5;

    public const RESEND_SECONDS = 60;

    public function __construct(private readonly AuditService $audit) {}

    /** Whether this request must wait for the user's email to be confirmed. */
    public static function blocks(Request $request): bool
    {
        $user = $request->user();

        return $user instanceof User
            && $user->email_verified_at === null
            && ! $user->currentAccessToken() instanceof PersonalAccessToken;
    }

    /**
     * Sends a fresh code, replacing any earlier one. Returns the seconds until
     * the next resend is allowed.
     */
    public function send(User $user): int
    {
        if ($user->email_verified_at !== null) {
            return 0;
        }
        $existing = DB::table('email_verification_codes')->where('user_id', $user->id)->first();
        if ($existing) {
            $wait = self::RESEND_SECONDS - (int) Carbon::parse($existing->sent_at)->diffInSeconds(now());
            if ($wait > 0) {
                throw new ApiException(ErrorCode::RateLimited, "Новый код можно запросить через {$wait} с.", ['retry_after' => $wait]);
            }
        }

        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
        DB::table('email_verification_codes')->updateOrInsert(['user_id' => $user->id], [
            'code_hash' => $this->hash($user, $code),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
            'sent_at' => now(),
        ]);
        $user->notify(new EmailVerificationCodeNotification($code, self::TTL_MINUTES));

        return self::RESEND_SECONDS;
    }

    public function verify(User $user, string $input): void
    {
        if ($user->email_verified_at !== null) {
            return;
        }
        $code = preg_replace('/\D/', '', $input);

        // The attempt counter must survive a wrong code, so errors are raised after the transaction.
        $error = DB::transaction(function () use ($user, $code) {
            $row = DB::table('email_verification_codes')->where('user_id', $user->id)->lockForUpdate()->first();
            if (! $row || Carbon::parse($row->expires_at)->isPast()) {
                return 'Код устарел. Запросите новый.';
            }
            if ($row->attempts >= self::MAX_ATTEMPTS) {
                return 'Слишком много попыток. Запросите новый код.';
            }
            if (! hash_equals($row->code_hash, $this->hash($user, (string) $code))) {
                DB::table('email_verification_codes')->where('id', $row->id)->increment('attempts');
                $left = self::MAX_ATTEMPTS - $row->attempts - 1;

                return $left > 0 ? "Неверный код. Осталось попыток: {$left}." : 'Слишком много попыток. Запросите новый код.';
            }

            DB::table('email_verification_codes')->where('id', $row->id)->delete();
            $user->forceFill(['email_verified_at' => now()])->save();
            $this->audit->record('auth.email_verified', $user, actor: Actor::user($user));

            return null;
        });

        if ($error !== null) {
            throw new ApiException(ErrorCode::ValidationFailed, $error, ['fields' => ['code' => [$error]]]);
        }
    }

    /** Marks an address confirmed by other means (a password reset link, an admin invitation). */
    public function markVerified(User $user): void
    {
        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
            DB::table('email_verification_codes')->where('user_id', $user->id)->delete();
        }
    }

    private function hash(User $user, string $code): string
    {
        return hash_hmac('sha256', $user->id.'|'.$code, (string) config('app.key'));
    }
}
