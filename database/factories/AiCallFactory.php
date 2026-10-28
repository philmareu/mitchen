<?php

namespace Database\Factories;

use App\Ai\Agents\KitchenUpdateReader;
use App\Models\AiCall;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AiCall>
 */
class AiCallFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $input = fake()->numberBetween(800, 3_000);
        $output = fake()->numberBetween(100, 600);

        return [
            'invocation_id' => (string) Str::uuid7(),
            'agent' => KitchenUpdateReader::class,
            'provider' => 'anthropic',
            'model' => 'claude-haiku-5-5',
            'input_tokens' => $input,
            'output_tokens' => $output,
            'cost' => ($input * 0.10 + $output * 0.50) / 1_000_000,
            'duration_ms' => fake()->numberBetween(900, 4_000),
        ];
    }

    public function failed(string $error = 'Connection refused'): static
    {
        return $this->state(fn (array $attributes) => [
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cost' => 0,
            'error' => $error,
        ]);
    }
}
