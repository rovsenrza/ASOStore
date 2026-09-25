<?php

namespace Database\Factories;

use App\Enums\AppVisibility;
use App\Enums\SourceType;
use App\Models\AppCategory;
use App\Models\AppPublisher;
use App\Models\CatalogApp;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CatalogApp>
 */
class CatalogAppFactory extends Factory
{
    protected $model = CatalogApp::class;

    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->slug(2),
            'name' => fake()->unique()->words(2, true),
            'subtitle' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'category_id' => AppCategory::factory(),
            'publisher_id' => AppPublisher::factory(),
            'source_type' => SourceType::OwnBuild,
            'visibility' => AppVisibility::Published,
            'age_rating' => '4+',
        ];
    }

    public function draft(): static
    {
        return $this->state(['visibility' => AppVisibility::Draft]);
    }

    public function hidden(): static
    {
        return $this->state(['visibility' => AppVisibility::Hidden]);
    }

    public function featured(int $rank = 1): static
    {
        return $this->state(['featured_rank' => $rank]);
    }
}
