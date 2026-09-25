<?php

namespace App\Http\Resources;

use App\Models\AppVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AppVersion
 */
class VersionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'version' => $this->version,
            'build_number' => $this->build_number,
            'release_notes' => $this->release_notes,
            'min_ios_version' => $this->min_ios_version,
            'released_at' => $this->released_at?->toIso8601ZuluString(),
        ];
    }
}
