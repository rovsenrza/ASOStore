<?php

namespace Database\Factories;

use App\Models\AppCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AppCategory>
 */
class AppCategoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->slug(2),
            'title' => fake()->unique()->words(2, true),
            'subtitle' => fake()->sentence(3),
            'sort_order' => fake()->numberBetween(0, 100),
        ];
    }
}
