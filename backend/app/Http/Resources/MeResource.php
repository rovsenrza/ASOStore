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
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $subscription = $this->activeSubscription;

        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'email' => $this->email,
            'roles' => $this->roleSlugs(),
            'subscription' => $subscription ? (new SubscriptionResource($subscription))->resolve($request) : null,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
