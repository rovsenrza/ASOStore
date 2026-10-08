<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The signed-in customer (GET /auth/me).
 *
 * @mixin User
 */
class MeResource extends JsonResource
{
    /**
     * @param  array{version: ?string, build_number: int}|null  $appUpdate  A newer storefront
     *                                                                      build than the caller's, for this device's enrolled team — null if none applies.
     */
    public function __construct($resource, private readonly ?array $appUpdate = null)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $subscription = $this->activeSubscription;

        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified' => $this->email_verified_at !== null,
            'roles' => $this->roleSlugs(),
            'subscription' => $subscription ? (new SubscriptionResource($subscription))->resolve($request) : null,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'app_update' => $this->appUpdate,
        ];
    }
}
