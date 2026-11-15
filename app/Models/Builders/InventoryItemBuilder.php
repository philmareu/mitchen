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

    /**
     * Items to use soon: something left, with a use-by date within this many days (or past).
     */
    /**
     * Items in the kitchen that aren't ready to use yet.
     */
    public function ripening(): static
    {
        return $this->whereDate('ready_on', '>', today());
    }

    public function useSoon(int $days = 7): static
    {
        return $this->notOut()->whereDate('expires_on', '<=', today()->addDays($days));
    }
}
