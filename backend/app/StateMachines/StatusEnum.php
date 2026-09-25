<?php

namespace App\StateMachines;

use BackedEnum;

/**
 * A status enum whose legal moves are declared next to its cases
 * (IMPLEMENTATION_PLAN §5.1).
 */
interface StatusEnum extends BackedEnum
{
    /**
     * @return list<static>
     */
    public function allowedTransitions(): array;

    public function canTransitionTo(StatusEnum $to): bool;

    public function isTerminal(): bool;
}
