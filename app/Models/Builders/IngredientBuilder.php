<?php

namespace App\Models\Builders;

use App\Models\Ingredient;
use App\Support\Names;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends Builder<Ingredient>
 */
class IngredientBuilder extends Builder
{
    /**
     * The ingredient with this name, ignoring case, surrounding spaces and singular or plural
     * ("lime" is "Limes"; see Names). Matched in PHP: one kitchen's catalog is small.
     */
    public function named(string $name): static
    {
        return $this->whereKey(Names::search($name, (clone $this)->pluck('name', 'id')) ?? []);
    }

    public function core(): static
    {
        return $this->where('is_core', true);
    }

    /**
     * Core ingredients down to their restock point (by default, nothing on hand above low).
     *
     * Restock points compare amounts across units ("12 oz" against "1 lb"), which SQL can't do
     * portably, so each core ingredient is checked in PHP. One kitchen's catalog is small.
     */
    public function needsRestock(): static
    {
        $ids = $this->model->newQuery()
            ->core()
            ->with('inventoryItems')
            ->get()
            ->filter(fn (Ingredient $ingredient): bool => $ingredient->needsRestocking())
            ->modelKeys();

        return $this->core()->whereKey($ids);
    }

    /**
     * Not on the shopping list at all. A ticked entry counts: it's in the cart until it's put away.
     */
    public function notOnShoppingList(): static
    {
        return $this->whereDoesntHave('shoppingListItems');
    }
}
