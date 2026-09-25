<?php

namespace App\Services\Audit;

use App\Enums\ActorType;
use App\Models\User;

/**
 * Who performed an audited action.
 */
final readonly class Actor
{
    public function __construct(
        public ActorType $type,
        public ?int $id = null,
        public ?string $label = null,
    ) {}

    public static function system(string $label = 'system'): self
    {
        return new self(ActorType::System, null, $label);
    }

    /**
     * Staff are named by email. Customers by public ID only: audit rows are
     * immutable, and an erased account must not stay readable in them (P8-SEC-02).
     */
    public static function user(User $user): self
    {
        return new self(ActorType::User, $user->id, $user->isStaff() ? $user->email : 'customer:'.$user->public_id);
    }

    /**
     * An unauthenticated caller identified only by the email they typed, masked
     *
     * (a***@example.com) so the audit trail keeps the domain for abuse analysis.
     */
    public static function anonymous(string $email): self
    {
        $email = mb_strtolower(trim($email));
        $at = strrpos($email, '@');
        $masked = $at === false ? '***' : mb_substr($email, 0, 1).'***'.substr($email, $at);

        return new self(ActorType::Anonymous, null, $masked);
    }

    public static function worker(string $workerId): self
    {
        return new self(ActorType::Worker, null, $workerId);
    }

    /**
     * The authenticated user for the current request, or the system.
     */
    public static function current(): self
    {
        $user = auth()->user();

        return $user instanceof User ? self::user($user) : self::system();
    }
}
