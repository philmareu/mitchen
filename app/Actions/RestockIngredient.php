<?php

namespace App\Actions;

use App\Enums\StockLevel;
use App\Enums\StorageLocation;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Support\Quantity;

class RestockIngredient
{
    /**
     * Record a fresh supply in one place. It replaces a low or out item there, adds to an
     * item measured in the same unit ("2 cans" + "2 cans"), or becomes a new item. "Out"
     * placeholders elsewhere are cleared since they no longer apply.
     */
    public function handle(Ingredient $ingredient, Quantity $quantity, StorageLocation $location): InventoryItem
    {
        $here = $ingredient->inventoryItems()
            ->where('location', $location)
            ->latest('updated_at')
            ->get();

        if ($item = $here->first(fn (InventoryItem $item): bool => $item->quantity->needsRestock())) {
            $item->quantity = $quantity;
        } elseif ($item = $here->first(fn (InventoryItem $item): bool => $item->quantity->plus($quantity) !== null)) {
            $item->quantity = $item->quantity->plus($quantity);
        } else {
            $item = $ingredient->inventoryItems()->make(['location' => $location, 'quantity' => $quantity]);
        }

        $item->save();

        $ingredient->inventoryItems()
            ->whereKeyNot($item->getKey())
            ->where('stock_level', StockLevel::Out)
            ->delete();

        return $item;
    }
}
