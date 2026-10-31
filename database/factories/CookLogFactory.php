<?php

namespace Database\Factories;

use App\Models\CookLog;
use App\Models\Recipe;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CookLog>
 */
class CookLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'recipe_id' => Recipe::factory(),
            'cooked_on' => fake()->dateTimeBetween('-1 month')->format('Y-m-d'),
            'thoughts' => fake()->sentence(),
            'next_time' => null,
        ];
    }

    /**
     * With notes for next time.
     */
    public function nextTime(string $notes = 'More salt.'): static
    {
        return $this->state(fn (array $attributes) => [
            'next_time' => $notes,
        ]);
    }
}
