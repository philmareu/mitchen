<?php

namespace Database\Factories;

use App\Enums\StockLevel;
use App\Enums\StorageLocation;
use App\Enums\Unit;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryItem>
 */
class InventoryItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ingredient_id' => Ingredient::factory(),
            'location' => fake()->randomElement(StorageLocation::cases()),
            'quantity' => Quantity::of(fake()->numberBetween(1, 4), fake()->randomElement([Unit::Jar, Unit::Can, Unit::Bag, Unit::Pound])),
        ];
    }

    public function level(StockLevel $level): static
    {
        return $this->state(fn (array $attributes) => [
            'quantity' => $level,
        ]);
    }

    public function quantity(string $quantity): static
    {
        return $this->state(fn (array $attributes) => [
            'quantity' => $quantity,
        ]);
    }
}
