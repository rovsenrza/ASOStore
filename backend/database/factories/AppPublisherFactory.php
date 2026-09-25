<?php

namespace Database\Factories;

use App\Models\AppPublisher;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AppPublisher>
 */
class AppPublisherFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'website' => fake()->url(),
            'support_email' => fake()->safeEmail(),
        ];
    }
}
