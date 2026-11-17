<?php

namespace Database\Factories;

use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Store>
 */
class StoreFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
        ];
    }

    /**
     * Takes orders online: its shop looks things up at this address, given as a search for milk.
     */
    public function online(string $searchUrl = 'https://shop.example.com/search?q=milk'): static
    {
        return $this->state(fn (array $attributes) => [
            'search_url' => $searchUrl,
        ]);
    }
}
