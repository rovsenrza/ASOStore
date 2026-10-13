<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

/*
 * Browser tests cannot read the verification email, so this replaces the pending code
 * for an address with a known one, hashed exactly as EmailVerificationService does:
 *   php tests/Support/set-email-code.php EMAIL CODE
 * Acts on the database the e2e server uses (scripts/e2e.sh exports DB_DATABASE).
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$email, $code] = [$argv[1], $argv[2]];
$user = User::query()->where('email', $email)->sole();
DB::table('email_verification_codes')->where('user_id', $user->id)->update([
    'code_hash' => hash_hmac('sha256', $user->id.'|'.$code, (string) config('app.key')),
    'attempts' => 0,
    'expires_at' => now()->addMinutes(15),
]) === 1 || throw new RuntimeException("No pending code for {$email}.");
