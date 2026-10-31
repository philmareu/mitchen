<?php

namespace App\Models\Builders;

use App\Models\Recipe;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends Builder<Recipe>
 */
class RecipeBuilder extends Builder
{
    /**
     * Recipes the kitchen has everything for (Recipe::missing() is empty). Substitutes make
     * this too fiddly for portable SQL, so it's worked out in PHP and returned as a whereKey()
     * scope, like IngredientBuilder::needsRestock().
     */
    public function readyToCook(): static
    {
        return $this->whereKey(
            Recipe::query()
                ->with(Recipe::KITCHEN)
                ->get()
                ->filter(fn (Recipe $recipe): bool => $recipe->missing() === [])
                ->modelKeys(),
        );
    }
}
