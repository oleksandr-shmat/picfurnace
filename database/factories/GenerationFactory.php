<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Generation>
 */
class GenerationFactory extends Factory
{
    protected $model = Generation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'user_id' => User::factory(),
            'status' => GenerationStatus::Pending,
            'prompt' => fake()->sentence(),
            'size' => '1024x1024',
            'quality' => 'low',
            'reference_image_urls' => [],
            'markup_percent' => 25,
            'estimated_price_min_nano' => 1_000,
            'estimated_price_expected_nano' => 2_000,
            'estimated_price_max_nano' => 3_000,
        ];
    }

    public function succeeded(int $providerCostNano = 1_000, int $chargedNano = 1_250): static
    {
        return $this->state(fn () => [
            'status' => GenerationStatus::Succeeded,
            'provider_id' => 'gen_'.Str::random(12),
            'provider_cost_nano' => $providerCostNano,
            'charged_nano' => $chargedNano,
            'completed_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => GenerationStatus::Failed,
            'provider_id' => 'gen_'.Str::random(12),
            'charged_nano' => 0,
            'failure_code' => 'generation_failed',
            'completed_at' => now(),
        ]);
    }
}
