<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Auth;
use LogicException;

/**
 * Checks an email and password without signing anyone in. Goes through the
 * session guard so Laravel's timing protection applies to unknown emails too.
 */
class CredentialVerifier
{
    public function verify(string $email, #[\SensitiveParameter] string $password): ?User
    {
        $guard = Auth::guard('web');

        if (! $guard instanceof SessionGuard) {
            throw new LogicException('The web guard must be a session guard.');
        }

        if (! $guard->validate(['email' => $email, 'password' => $password])) {
            return null;
        }

        $user = $guard->getLastAttempted();

        return $user instanceof User ? $user : null;
    }
}
