<?php

namespace App\StateMachines;

/**
 * Shared behaviour for status enums that declare their own transition map.
 *
 * @phpstan-require-implements StatusEnum
 */
trait HasTransitions
{
    public function canTransitionTo(StatusEnum $to): bool
    {
        return $to instanceof self && in_array($to, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }
}
