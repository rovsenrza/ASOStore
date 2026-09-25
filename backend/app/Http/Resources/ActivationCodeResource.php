<?php

namespace App\Http\Resources;

use App\Models\ActivationCode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Never includes the code itself — only its last four characters.
 *
 * @mixin ActivationCode
 */
class ActivationCodeResource extends JsonResource
{
    public const RELATIONS = ['creator', 'redeemer'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'batch_id' => $this->batch_id,
            'hint' => $this->code_hint,
            'status' => $this->effectiveStatus()->value,
            'plan' => $this->plan,
            'duration_days' => $this->duration_days,
            'expires_at' => $this->expires_at?->toIso8601ZuluString(),
            'note' => $this->note,
            'created_by' => ['id' => $this->creator->public_id, 'email' => $this->creator->email],
            'redeemed_by' => $this->redeemer ? ['id' => $this->redeemer->public_id, 'email' => $this->redeemer->email] : null,
            'redeemed_at' => $this->redeemed_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
