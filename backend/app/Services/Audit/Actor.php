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

    public static function user(User $user): self
    {
        return new self(ActorType::User, $user->id, $user->email);
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
