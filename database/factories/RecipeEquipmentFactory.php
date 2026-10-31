<?php

namespace Database\Factories;

use App\Models\Recipe;
use App\Models\RecipeEquipment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecipeEquipment>
 */
class RecipeEquipmentFactory extends Factory
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
            'name' => ucfirst(fake()->unique()->words(2, true)),
        ];
    }
}
