<?php

use Illuminate\Support\Facades\DB;

it('deletes expired cache rows and locks, and keeps live ones', function () {
    DB::table('cache')->insert([
        ['key' => 'nonce-old', 'value' => 'x', 'expiration' => time() - 10],
        ['key' => 'nonce-older', 'value' => 'x', 'expiration' => time() - 3600],
        ['key' => 'portal-groups', 'value' => 'x', 'expiration' => time() + 86400],
    ]);
    DB::table('cache_locks')->insert([
        ['key' => 'lock-old', 'owner' => 'a', 'expiration' => time() - 5],
        ['key' => 'lock-live', 'owner' => 'b', 'expiration' => time() + 60],
    ]);

    $this->artisan('cache:prune-database', ['--batch' => 100])->assertSuccessful();

    expect(DB::table('cache')->pluck('key')->all())->toBe(['portal-groups'])
        ->and(DB::table('cache_locks')->pluck('key')->all())->toBe(['lock-live']);
});
