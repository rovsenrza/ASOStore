<?php

namespace Database\Factories;

use App\Models\AppVersion;
use App\Models\CatalogApp;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AppVersion>
 */
class AppVersionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'app_id' => CatalogApp::factory(),
            'version' => fake()->unique()->numerify('#.#.#'),
            'build_number' => (string) fake()->unique()->numberBetween(1, 99999),
            'release_notes' => fake()->sentence(),
            'min_ios_version' => '17.0',
            'released_at' => fake()->dateTimeBetween('-1 year'),
        ];
    }
}
