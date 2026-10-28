<?php

namespace App\Actions;

use App\Enums\IngredientCategory;
use App\Models\Ingredient;
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
