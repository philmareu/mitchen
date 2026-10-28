<?php

namespace Database\Factories;

use App\Enums\IngredientCategory;
use App\Models\ShoppingListItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShoppingListItem>
 */
class ShoppingListItemFactory extends Factory
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
        ];
    }

    public function bought(): static
    {
        return $this->state(fn (array $attributes) => [
            'bought_at' => now(),
        ]);
    }
}
