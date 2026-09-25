<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

/**
 * Single entry point for audit events (IMPLEMENTATION_PLAN §5.3).
 *
 * Call inside the same DB transaction as the change it describes so the
 * event and the change commit or roll back together.
 */
class AuditService
{
    /**
     * Keys whose values never reach the audit log (FULL_PLAN §7, §13).
     */
    private const REDACTED_KEYS = [
        'udid', 'udid_encrypted', 'udid_hash', 'password', 'token', 'secret',
        'private_key', 'api_key', 'refresh_token', 'access_token', 'remember_token',
    ];

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(
        string $action,
        ?Model $subject = null,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
        ?Actor $actor = null,
    ): AuditLog {
        $actor ??= Actor::current();

        return AuditLog::create([
            'occurred_at' => now(),
            'actor_type' => $actor->type,
            'actor_id' => $actor->id,
            'actor_label' => $actor->label,
            'action' => $action,
            'subject_type' => $subject ? Str::snake(class_basename($subject)) : null,
            'subject_id' => $subject ? (string) $subject->getRouteKey() : null,
            'before' => $before === null ? null : self::redact($before),
            'after' => $after === null ? null : self::redact($after),
            'reason' => $reason,
            'request_id' => Context::get('request_id'),
            'correlation_id' => Context::get('correlation_id', Context::get('request_id')),
            'ip' => app()->runningInConsole() ? null : request()->ip(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public static function redact(array $values): array
    {
        foreach ($values as $key => $value) {
            if (in_array(strtolower((string) $key), self::REDACTED_KEYS, true)) {
                $values[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $values[$key] = self::redact($value);
            }
        }

        return $values;
    }
}
