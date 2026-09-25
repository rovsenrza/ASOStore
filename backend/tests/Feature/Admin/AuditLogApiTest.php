<?php

use App\Enums\RoleSlug;
use App\Services\Audit\Actor;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\Context;

it('filters by action prefix and request id and shows actor public ids', function () {
    $support = userWithRoles(RoleSlug::Support);
    $audit = app(AuditService::class);

    Context::add('request_id', 'req-aaaaaaaa');
    $audit->record('user.created', actor: Actor::user($support));
    Context::add('request_id', 'req-bbbbbbbb');
    $audit->record('user.roles_changed', actor: Actor::user($support));
    $audit->record('activation_code.redeemed', actor: Actor::system());

    asStaff($support)->getJson('/api/v1/admin/audit-logs?action=user.')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.action', 'user.roles_changed')
        ->assertJsonPath('data.0.actor.id', $support->public_id);

    $this->getJson('/api/v1/admin/audit-logs?request_id=req-aaaaaaaa')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.action', 'user.created');
});
