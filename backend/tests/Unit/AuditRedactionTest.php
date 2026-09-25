<?php

use App\Services\Audit\AuditService;

it('redacts sensitive keys at any depth', function () {
    $redacted = AuditService::redact([
        'status' => 'READY',
        'udid' => '00008030-001A2B3C4D5E6F70',
        'Password' => 'secret',
        'credentials' => ['api_key' => 'k', 'key_id' => 'ABC123'],
    ]);

    expect($redacted)->toBe([
        'status' => 'READY',
        'udid' => '[REDACTED]',
        'Password' => '[REDACTED]',
        'credentials' => ['api_key' => '[REDACTED]', 'key_id' => 'ABC123'],
    ]);
});
