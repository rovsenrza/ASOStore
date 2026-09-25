<?php

namespace App\Http\Resources;

use App\Models\CatalogApp;
use App\Models\User;
use App\Services\Catalog\InstallStateResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Catalog card shape used by lists and the feed.
 *
 * @mixin CatalogApp
 */
class AppSummaryResource extends JsonResource
{
    public const RELATIONS = ['category', 'publisher', 'latestVersion', ...InstallStateResolver::REQUIRED_RELATIONS];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user('sanctum');

        return [
            'id' => $this->public_id,
            'slug' => $this->slug,
            'name' => $this->name,
            'subtitle' => $this->subtitle,
            'category' => [
                'id' => $this->category->public_id,
                'slug' => $this->category->slug,
                'title' => $this->category->title,
            ],
            'publisher' => [
                'id' => $this->publisher->public_id,
                'name' => $this->publisher->name,
            ],
            'icon_url' => $this->iconUrl(),
            'age_rating' => $this->age_rating,
            'latest_version' => $this->latestVersion ? [
                'version' => $this->latestVersion->version,
                'build_number' => $this->latestVersion->build_number,
                'min_ios_version' => $this->latestVersion->min_ios_version,
                'released_at' => $this->latestVersion->released_at?->toIso8601ZuluString(),
                'size_bytes' => $this->publishedArtifact?->size_bytes,
            ] : null,
            'install_state' => app(InstallStateResolver::class)->resolve(
                $this->resource,
                $user instanceof User ? $user : null,
            ),
        ];
    }
}
