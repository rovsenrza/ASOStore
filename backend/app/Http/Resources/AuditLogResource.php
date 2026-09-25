<?php

namespace App\Http\Resources;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AuditLog
 */
class AuditLogResource extends JsonResource
{
    /**
     * @param  array<int, string>  $userPublicIds  Internal user id → public id, for the page being rendered.
     */
    public function __construct(AuditLog $resource, private readonly array $userPublicIds = [])
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'occurred_at' => $this->occurred_at->format('Y-m-d\TH:i:s.up'),
            'actor' => [
                'type' => $this->actor_type->value,
                'id' => $this->actor_id !== null ? ($this->userPublicIds[$this->actor_id] ?? null) : null,
                'label' => $this->actor_label,
            ],
            'action' => $this->action,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'before' => $this->before,
            'after' => $this->after,
            'reason' => $this->reason,
            'request_id' => $this->request_id,
            'ip' => $this->ip,
        ];
    }
}
