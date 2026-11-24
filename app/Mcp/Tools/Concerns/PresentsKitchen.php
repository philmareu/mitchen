<?php

namespace App\Mcp\Tools\Concerns;

use App\Models\Equipment;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\ShoppingListItem;

/**
 * How the tools show the kitchen to an assistant: enum values rather than labels, so it can
 * pass them back, and quantities as text Mitchen reads.
 */
trait PresentsKitchen
{
    /**
     * @return array{name: string, category: string|null, core: bool, always_on_hand: bool, restock_point: string|null, stock_target: string|null, needs_restock: bool, lead_time: string|null, on_hand: list<array<string, mixed>>}
     */
    protected function presentIngredient(Ingredient $ingredient): array
    {
        return [
            'name' => $ingredient->name,
            'category' => $ingredient->category?->value,
            'core' => $ingredient->is_core,
            'always_on_hand' => $ingredient->always_on_hand,
            'restock_point' => $ingredient->is_core ? ($ingredient->restock_point->format() ?: null) : null,
            'stock_target' => $ingredient->is_core ? ($ingredient->stock_target->format() ?: null) : null,
            'needs_restock' => $ingredient->needsRestocking(),
            'lead_time' => $ingredient->leadTime(),
            'on_hand' => $ingredient->inventoryItems->map($this->presentItem(...))->values()->all(),
        ];
    }

    /**
     * @return array{id: int, quantity: string, location: string, use_by: string|null, ripening_until: string|null}
     */
    protected function presentItem(InventoryItem $item): array
    {
        return [
            'id' => $item->id,
            'quantity' => $item->quantity->format() ?: 'some',
            'location' => $item->location->value,
            'use_by' => $item->expires_on?->toDateString(),
            'ripening_until' => $item->ripeningUntil()?->toDateString(),
        ];
    }

    /**
     * @return array{name: string, category: string|null, brand: string|null, size: string|null, count: int, notes: string|null}
     */
    protected function presentEquipment(Equipment $piece): array
    {
        return [
            'name' => $piece->name,
            'category' => $piece->category?->value,
            'brand' => $piece->brand,
            'size' => $piece->size,
            'count' => $piece->count,
            'notes' => $piece->notes,
        ];
    }

    /**
     * @return array{id: int, name: string, quantity: string|null, aisle: string}
     */
    protected function presentEntry(ShoppingListItem $entry): array
    {
        return [
            'id' => $entry->id,
            'name' => $entry->name,
            'quantity' => $entry->quantity,
            'aisle' => $entry->category->value,
        ];
    }
}
