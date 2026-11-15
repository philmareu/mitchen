<?php

namespace App\Actions;

use App\Enums\StockLevel;
use App\Enums\StorageLocation;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Support\Quantity;
use Carbon\CarbonInterface;

class RestockIngredient
{
    /**
     * Record a fresh supply in one place. It replaces a low or out item there, adds to an
     * item measured in the same unit ("2 cans" + "2 cans"), or becomes a new item. "Out"
     * placeholders elsewhere are cleared since they no longer apply. Supply that isn't ready
     * yet ($readyOn) is only added to an item ripening until the same day, so hard avocados
     * don't join ripe ones.
     */
    public function handle(Ingredient $ingredient, Quantity $quantity, StorageLocation $location, ?CarbonInterface $readyOn = null): InventoryItem
    {
        $here = $ingredient->inventoryItems()
            ->where('location', $location)
            ->latest('updated_at')
            ->get();

        $fresh = $ingredient->inventoryItems()->make(['location' => $location]);
        $fresh->ripenUntil($readyOn);

        if ($item = $here->first(fn (InventoryItem $item): bool => $item->quantity->needsRestock())) {
            $item->quantity = $quantity;
            $item->ready_on = $fresh->ready_on;
        } elseif ($item = $here->first(fn (InventoryItem $item): bool => $item->quantity->plus($quantity) !== null && $item->ripeningUntil()?->toDateString() === $fresh->ripeningUntil()?->toDateString())) {
            $item->quantity = $item->quantity->plus($quantity);
        } else {
            $item = $fresh;
            $item->quantity = $quantity;
        }

        $item->save();

        $ingredient->inventoryItems()
            ->whereKeyNot($item->getKey())
            ->where('stock_level', StockLevel::Out)
            ->delete();

        return $item;
    }
}
