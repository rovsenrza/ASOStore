<?php

namespace App\Enums;

/**
 * Who performed an audited action.
 */
enum ActorType: string
{
    case User = 'user';
    case System = 'system';
    case Worker = 'worker';
    /** Unauthenticated caller, e.g. a failed sign-in. */
    case Anonymous = 'anonymous';
}
