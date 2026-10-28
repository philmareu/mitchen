<?php

namespace App\Actions;

use App\Enums\StockLevel;
use App\Models\InventoryItem;

class UseUpInventoryItem
{
    /**
     * A core ingredient stays in the inventory marked "out" so it lands on the restock list.
     * A one-off ingredient is simply gone, so its item is removed.
     *
     * @return bool Whether the item was kept.
     */
    public function handle(InventoryItem $item): bool
    {
        if ($item->ingredient->is_core) {
            $item->update(['quantity' => StockLevel::Out]);

            return true;
        }

        $item->delete();

        return false;
    }
}
