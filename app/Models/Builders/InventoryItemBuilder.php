<?php

namespace App\Models\Builders;

use App\Enums\StockLevel;
use App\Models\InventoryItem;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends Builder<InventoryItem>
 */
class InventoryItemBuilder extends Builder
{
    /**
     * Items worth cooking with: anything not marked low or out.
     */
    public function inStock(): static
    {
        return $this->where(fn (self $query) => $query
            ->whereNull('stock_level')
            ->orWhereNotIn('stock_level', [StockLevel::Low, StockLevel::Out]));
    }

    /**
     * Items with something left: anything not marked out, low included.
     */
    public function notOut(): static
    {
        return $this->where(fn (self $query) => $query
            ->whereNull('stock_level')
            ->orWhere('stock_level', '!=', StockLevel::Out));
    }

    public function runningLow(): static
    {
        return $this->whereIn('stock_level', [StockLevel::Low, StockLevel::Out]);
    }
}
