<?php

namespace Database\Factories;

use App\Models\Purchase;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Purchase>
 */
class PurchaseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'purchased_on' => fake()->dateTimeBetween('-2 months')->format('Y-m-d'),
            'total' => fake()->randomFloat(2, 5, 150),
        ];
    }
}
