<?php

namespace App\Http\Resources;

use App\Models\AppScreenshot;
use App\Models\AppVersion;
use App\Models\CatalogApp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A listing as operators see it, including drafts and deleted listings.
 *
 * @mixin CatalogApp
 */
class AdminAppResource extends JsonResource
{
    public const RELATIONS = ['category', 'publisher', 'latestVersion', 'publishedArtifact'];

    public function __construct(CatalogApp $resource, private readonly bool $detailed = false)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->public_id,
            'slug' => $this->slug,
            'name' => $this->name,
            'subtitle' => $this->subtitle,
            'category' => ['id' => $this->category->public_id, 'slug' => $this->category->slug, 'title' => $this->category->title, 'kind' => $this->category->kind->value],
            'publisher' => ['id' => $this->publisher->public_id, 'name' => $this->publisher->name],
            'source_type' => $this->source_type->value,
            'visibility' => $this->visibility->value,
            'age_rating' => $this->age_rating,
            'featured_rank' => $this->featured_rank,
            'bundle_identifier' => $this->bundle_identifier,
            'icon_url' => $this->iconUrl(),
            'banner_url' => $this->bannerUrl(),
            'is_storefront' => $this->is_storefront,
            'latest_version' => $this->latestVersion?->version,
            'has_published_artifact' => $this->publishedArtifact !== null,
            'deleted_at' => $this->deleted_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];

        if ($this->detailed) {
            $data += [
                'description' => $this->description,
                'support_url' => $this->support_url,
                'privacy_url' => $this->privacy_url,
                'app_store_id' => $this->app_store_id,
                'screenshots' => $this->screenshots->map(fn (AppScreenshot $screenshot) => $screenshot->present())->all(),
                'versions' => $this->versions->map(fn (AppVersion $version) => [
                    'id' => $version->public_id,
                    'version' => $version->version,
                    'build_number' => $version->build_number,
                    'min_ios_version' => $version->min_ios_version,
                    'release_notes' => $version->release_notes,
                    'released_at' => $version->released_at?->toIso8601ZuluString(),
                ])->all(),
            ];
        }

        return $data;
    }
}
