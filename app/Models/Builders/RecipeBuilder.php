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
     * Recipes the kitchen has everything for: every ingredient that isn't optional is always on
     * hand or has some left, and all the equipment is there. Matches Recipe::missing().
     */
    public function readyToCook(): static
    {
        return $this
            ->whereDoesntHave('ingredients', fn (Builder $lines) => $lines
                ->where('is_optional', false)
                ->whereDoesntHave('ingredient', fn (IngredientBuilder $ingredient) => $ingredient->where(fn (IngredientBuilder $query) => $query
                    ->where('always_on_hand', true)
                    ->orWhereHas('inventoryItems', fn (InventoryItemBuilder $items) => $items->notOut()))))
            ->whereDoesntHave('equipment', fn (Builder $pieces) => $pieces->whereNull('equipment_id'));
    }
}
