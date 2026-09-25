<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A user as operators see it. Requires roles and activeSubscription loaded.
 *
 * @mixin User
 */
class AdminUserResource extends JsonResource
{
    public const RELATIONS = ['roles', 'activeSubscription'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'email' => $this->email,
            'status' => $this->status->value,
            'roles' => $this->roleSlugs(),
            'totp_enabled' => $this->hasConfirmedTotp(),
            'subscription' => $this->activeSubscription ? (new SubscriptionResource($this->activeSubscription))->resolve($request) : null,
            'last_login_at' => $this->last_login_at?->toIso8601ZuluString(),
            'deletion_requested_at' => $this->deletion_requested_at?->toIso8601ZuluString(),
            'erased_at' => $this->erased_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
