<?php

namespace App\Http\Resources;

use App\Models\CatalogApp;
use Illuminate\Http\Request;

/**
 * App page shape: the summary plus long-form content.
 *
 * @mixin CatalogApp
 */
class AppDetailResource extends AppSummaryResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $summary = parent::toArray($request);

        $summary['description'] = $this->description;
        $summary['release_notes'] = $this->latestVersion?->release_notes;
        $summary['screenshots'] = $this->screenshots->map(fn ($screenshot) => [
            'url' => $screenshot->url(),
            'width' => $screenshot->width,
            'height' => $screenshot->height,
        ])->all();
        $summary['publisher']['website'] = $this->publisher->website;
        $summary['support_url'] = $this->support_url;
        $summary['privacy_url'] = $this->privacy_url;

        return $summary;
    }
}
