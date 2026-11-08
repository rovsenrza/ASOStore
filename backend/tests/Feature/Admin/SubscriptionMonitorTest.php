<?php

use App\Enums\RoleSlug;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\User;

// Subscription monitor: who has how much access left, soonest first, with window counts.

function customerEnding(?DateTimeInterface $end, SubscriptionStatus $status = SubscriptionStatus::Active): User
{
    $user = userWithRoles(RoleSlug::Customer);
    Subscription::create(['user_id' => $user->id, 'plan' => 'standard', 'status' => $status, 'starts_at' => now()->subDays(30), 'ends_at' => $end]);

    return $user;
}

beforeEach(function () {
    $this->in12h = customerEnding(now()->addHours(12));
    $this->in5d = customerEnding(now()->addDays(5));
    $this->in20d = customerEnding(now()->addDays(20));
    $this->ended2d = customerEnding(now()->subDays(2));
    $this->ended30d = customerEnding(now()->subDays(30));
    $this->forever = customerEnding(null);
    // A cancelled subscription does not count.
    customerEnding(now()->addDays(100), SubscriptionStatus::Cancelled);
    // The latest of several active subscriptions decides.
    Subscription::create(['user_id' => $this->in12h->id, 'plan' => 'standard', 'status' => SubscriptionStatus::Active, 'starts_at' => now()->subDays(60), 'ends_at' => now()->subDays(29)]);
});

it('lists active customers soonest first with the time left and window counts', function () {
    $response = asStaff(userWithRoles(RoleSlug::Support))->getJson('/api/v1/admin/subscriptions')->assertOk();

    expect(collect($response->json('data'))->pluck('number')->all())->toBe([$this->in12h->id, $this->in5d->id, $this->in20d->id, $this->forever->id])
        ->and($response->json('data.0.seconds_left'))->toBeGreaterThan(11 * 3600)->toBeLessThanOrEqual(12 * 3600)
        ->and($response->json('data.3.unlimited'))->toBeTrue()
        ->and($response->json('meta.summary'))->toMatchArray([
            'active' => 4, 'ending1' => 1, 'ending3' => 1, 'ending7' => 2, 'expired7' => 1, 'expired' => 2, 'unlimited' => 1, 'all' => 6,
        ]);
});

it('filters by window and finds a customer by number', function () {
    asStaff(userWithRoles(RoleSlug::Admin))->getJson('/api/v1/admin/subscriptions?state=ending7')
        ->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/admin/subscriptions?state=expired')
        ->assertOk()->assertJsonPath('data.0.number', $this->ended2d->id)->assertJsonPath('data.1.number', $this->ended30d->id);
    $this->getJson('/api/v1/admin/subscriptions?state=all&q='.$this->in5d->id)
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.email', $this->in5d->email);
});

it('is closed to customers', function () {
    asStaff(userWithRoles(RoleSlug::Customer))->getJson('/api/v1/admin/subscriptions')->assertForbidden();
});
