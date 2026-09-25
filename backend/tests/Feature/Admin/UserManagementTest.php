<?php

use App\Enums\RoleSlug;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->admin = userWithRoles(RoleSlug::Admin);
});

it('lists and searches users', function () {
    userWithRoles(RoleSlug::Customer)->update(['name' => 'Мария Иванова']);
    userWithRoles(RoleSlug::Support);

    asStaff($this->admin)->getJson('/api/v1/admin/users?'.http_build_query(['q' => 'мария']))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Мария Иванова')
        ->assertJsonPath('data.0.roles', ['customer'])
        ->assertJsonPath('data.0.totp_enabled', false);

    $this->getJson('/api/v1/admin/users?role=support')->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/admin/users?per_page=2')->assertJsonPath('meta.pagination.total', 3);
});

it('shows a user with their subscriptions and recent activity', function () {
    $customer = userWithRoles(RoleSlug::Customer);
    asBrowser()->postJson('/api/v1/auth/login', ['email' => $customer->email, 'password' => 'password']);
    forgetGuards();

    asStaff($this->admin)->getJson("/api/v1/admin/users/{$customer->public_id}")
        ->assertOk()
        ->assertJsonPath('data.subscriptions', [])
        ->assertJsonPath('data.recent_activity.0.action', 'auth.login')
        ->assertJsonPath('data.recent_activity.0.actor.id', $customer->public_id);
});

it('creates staff accounts and emails a password link', function () {
    Notification::fake();

    asStaff($this->admin)->postJson('/api/v1/admin/users', [
        'name' => 'Оператор',
        'email' => 'Operator@Example.com',
        'roles' => ['support'],
    ])->assertCreated()->assertJsonPath('data.roles', ['support']);

    $operator = User::where('email', 'operator@example.com')->sole();
    Notification::assertSentTo($operator, ResetPasswordNotification::class);
    expect(AuditLog::where('action', 'user.created')->sole()->after)->toBe(['email' => 'operator@example.com', 'roles' => ['support']]);
});

it('suspends a user, ends their sessions and records the reason', function () {
    $customer = userWithRoles(RoleSlug::Customer);
    $customer->createToken('ios');

    asStaff($this->admin)->patchJson("/api/v1/admin/users/{$customer->public_id}", ['status' => 'SUSPENDED', 'reason' => 'Жалоба'])
        ->assertOk()
        ->assertJsonPath('data.status', 'SUSPENDED');

    expect($customer->fresh()->status)->toBe(UserStatus::Suspended)
        ->and($customer->tokens()->count())->toBe(0)
        ->and(AuditLog::where('action', 'user.status_changed')->sole()->reason)->toBe('Жалоба');
});

it('requires a reason for status and role changes', function () {
    $customer = userWithRoles(RoleSlug::Customer);

    asStaff($this->admin)->patchJson("/api/v1/admin/users/{$customer->public_id}", ['status' => 'SUSPENDED'])
        ->assertUnprocessable()->assertJsonPath('error.details.fields.reason.0', 'Заполните поле «причина».');
    $this->putJson("/api/v1/admin/users/{$customer->public_id}/roles", ['roles' => ['support']])->assertUnprocessable();
});

it('changes roles with a before/after audit record', function () {
    $customer = userWithRoles(RoleSlug::Customer);

    asStaff($this->admin)->putJson("/api/v1/admin/users/{$customer->public_id}/roles", ['roles' => ['support', 'customer'], 'reason' => 'Новый сотрудник'])
        ->assertOk()
        ->assertJsonPath('data.roles', ['customer', 'support']);

    $event = AuditLog::where('action', 'user.roles_changed')->sole();
    expect($event->before)->toBe(['roles' => ['customer']])
        ->and($event->after)->toBe(['roles' => ['customer', 'support']]);
});

it('stops admins from locking themselves out', function () {
    asStaff($this->admin)->putJson("/api/v1/admin/users/{$this->admin->public_id}/roles", ['roles' => ['support'], 'reason' => 'x'])
        ->assertStatus(409)->assertJsonPath('error.code', 'CONFLICT');
    $this->patchJson("/api/v1/admin/users/{$this->admin->public_id}", ['status' => 'SUSPENDED', 'reason' => 'x'])
        ->assertStatus(409);
});

it('resets a lost authenticator', function () {
    $support = userWithRoles(RoleSlug::Support);
    $support->forceFill(['totp_secret' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567', 'totp_confirmed_at' => now()])->save();

    asStaff($this->admin)->postJson("/api/v1/admin/users/{$support->public_id}/totp/reset", ['reason' => 'Потерян телефон'])
        ->assertOk()
        ->assertJsonPath('data.totp_enabled', false);

    expect($support->fresh()->totp_secret)->toBeNull();
});
