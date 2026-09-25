<?php

namespace Database\Factories;

use App\Enums\ArtifactStatus;
use App\Enums\SourceType;
use App\Models\AppArtifact;
use App\Models\CatalogApp;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AppArtifact>
 */
class AppArtifactFactory extends Factory
{
    public function definition(): array
    {
        $sha = hash('sha256', fake()->unique()->uuid());

        return [
            'app_id' => CatalogApp::factory(),
            'sha256' => $sha,
            'size_bytes' => fake()->numberBetween(1_000_000, 500_000_000),
            'storage_disk' => 'artifacts',
            'storage_path' => 'originals/'.$sha.'.ipa',
            'original_filename' => fake()->slug(2).'.ipa',
            'source_type' => SourceType::OwnBuild,
            'uploaded_by' => User::factory(),
            'declaration_version' => '2026-09-v1',
            'declaration_accepted_at' => now(),
            'status' => ArtifactStatus::Uploaded,
        ];
    }

    public function status(ArtifactStatus $status): static
    {
        return $this->state(['status' => $status]);
    }
}
