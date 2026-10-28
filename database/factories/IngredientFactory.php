<?php

namespace Database\Factories;

use App\Enums\IngredientCategory;
use App\Models\Ingredient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ingredient>
 */
class IngredientFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'category' => fake()->randomElement(IngredientCategory::cases()),
            'is_core' => false,
        ];
    }

    public function core(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_core' => true,
        ]);
    }
}
