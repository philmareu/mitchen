<?php

namespace Database\Factories;

use App\Enums\IngredientCategory;
use App\Enums\Sourcing;
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

    /**
     * A special trip or an order, taking about this many days.
     */
    public function hardToFind(?int $days = null): static
    {
        return $this->state(fn (array $attributes) => [
            'sourcing' => Sourcing::HardToFind,
            'lead_days' => $days,
        ]);
    }

    /**
     * Homemade: a batch takes this many days.
     */
    public function makeAhead(?int $days = null): static
    {
        return $this->state(fn (array $attributes) => [
            'sourcing' => Sourcing::MakeAhead,
            'lead_days' => $days,
        ]);
    }

    /**
     * In the stores these months (1-12).
     *
     * @param  list<int>  $months
     */
    public function seasonal(array $months): static
    {
        return $this->state(fn (array $attributes) => [
            'sourcing' => Sourcing::Seasonal,
            'season' => $months,
        ]);
    }
}
