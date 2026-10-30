<?php

namespace App\Actions;

use App\Enums\IngredientCategory;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\ShoppingListItem;

class AddToShoppingList
{
    /**
     * Put something on the list once: if it's already there and not bought yet, that entry is
     * kept (with the new quantity, when one is given) rather than added twice.
     */
    public function handle(Ingredient|string $item, ?string $quantity = null, ?IngredientCategory $category = null): ShoppingListItem
    {
        $name = $item instanceof Ingredient ? $item->name : trim($item);
        $quantity = filled($quantity) ? trim($quantity) : null;

        $entry = ShoppingListItem::query()
            ->toBuy()
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->first()
            ?? new ShoppingListItem(['name' => $name, 'category' => $category ?? IngredientCategory::Other]);

        if ($quantity !== null || ! $entry->exists) {
            $entry->quantity = $quantity ?? $entry->quantity;
        }

        $entry->save();

        return $entry;
    }

    /**
     * Add some of a recipe's ingredients, with the amount it calls for when that's an amount
     * Mitchen can read ("2 cups", scaled with the recipe), and none for "to taste".
     *
     * @param  list<int>  $lineIds  Its ingredient lines to add.
     * @return list<string> The names added.
     */
    public function fromRecipe(Recipe $recipe, array $lineIds, float $scale = 1): array
    {
        return $recipe->ingredients
            ->whereIn('id', $lineIds)
            ->map(fn (RecipeIngredient $line): string => $this->handle($line->ingredient ?? $line->name, $line->measuredQuantity($scale)?->format())->name)
            ->values()
            ->all();
    }

    /**
     * Add every ingredient on the restock list that isn't on the shopping list yet.
     *
     * @return list<string> The names added.
     */
    public function restockList(): array
    {
        return Ingredient::query()
            ->needsRestock()
            ->notOnShoppingList()
            ->orderBy('name')
            ->get()
            ->map(fn (Ingredient $ingredient): string => $this->handle($ingredient)->name)
            ->all();
    }
}
