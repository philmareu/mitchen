<?php

namespace App\Actions;

use App\Enums\ChangeKind;
use App\Enums\IngredientCategory;
use App\Enums\StorageLocation;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\ShoppingListItem;
use App\Support\KitchenUpdate;
use App\Support\ProposedChange;
use App\Support\PurchaseDetails;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Turns changes an assistant worked out with the cook (over the MCP server) into an update to
 * apply, checked against the catalog like ReadKitchenUpdate checks its own reader's: catalog
 * names keep their spelling and aisle, an item id only counts for its own ingredient, a change
 * that doesn't say where goes where the ingredient already is (new supply replaces the low or
 * out item), else where its aisle usually goes, and anything bought comes off the shopping list.
 */
class PrepareKitchenChanges
{
    /**
     * @param  list<array<string, mixed>>  $changes  Validated: kind and ingredient, with any of quantity, location, inventory_item_id, category, expires_on and price.
     * @param  list<int>  $boughtFromList  Shopping list entries the assistant says were bought.
     */
    public function handle(array $changes, array $boughtFromList = [], PurchaseDetails $purchase = new PurchaseDetails): KitchenUpdate
    {
        $changes = array_map($this->change(...), $changes);

        return new KitchenUpdate(
            changes: $changes,
            boughtFromList: $this->boughtFromList($boughtFromList, $changes),
            purchase: $purchase,
        );
    }

    /**
     * @param  array<string, mixed>  $change
     */
    private function change(array $change): ProposedChange
    {
        $kind = ChangeKind::from($change['kind']);
        $name = trim((string) $change['ingredient']);

        $ingredient = Ingredient::query()->named($name)->with('inventoryItems')->first();
        $items = $ingredient?->inventoryItems->sortByDesc('updated_at') ?? new Collection;

        $item = in_array($kind, [ChangeKind::Bought, ChangeKind::Need], true)
            ? null
            : $items->firstWhere('id', (int) ($change['inventory_item_id'] ?? 0));

        $category = $ingredient->category
            ?? IngredientCategory::tryFrom((string) ($change['category'] ?? ''))
            ?? IngredientCategory::Other;

        return new ProposedChange(
            kind: $kind,
            ingredient: $ingredient->name ?? Str::ucfirst($name),
            quantity: $kind === ChangeKind::UsedUp || blank($change['quantity'] ?? null) ? null : trim((string) $change['quantity']),
            location: StorageLocation::tryFrom((string) ($change['location'] ?? ''))
                ?? $item->location
                ?? $this->whereItIs($kind, $items)
                ?? $category->usualLocation(),
            inventoryItemId: $item?->id,
            category: $category,
            expiresOn: blank($change['expires_on'] ?? null) ? null : CarbonImmutable::parse($change['expires_on'])->startOfDay(),
            price: $kind === ChangeKind::Bought && is_numeric($change['price'] ?? null) ? round((float) $change['price'], 2) : null,
        );
    }

    /**
     * @param  Collection<int, InventoryItem>  $items  Newest first.
     */
    private function whereItIs(ChangeKind $kind, Collection $items): ?StorageLocation
    {
        $item = $kind === ChangeKind::Bought
            ? $items->first(fn (InventoryItem $item): bool => $item->quantity->needsRestock()) ?? $items->first()
            : $items->first();

        return $item?->location;
    }

    /**
     * Entries still to buy that the assistant named, plus any whose ingredient this update buys.
     *
     * @param  list<int>  $ids
     * @param  list<ProposedChange>  $changes
     * @return list<int>
     */
    private function boughtFromList(array $ids, array $changes): array
    {
        $bought = collect($changes)
            ->filter(fn (ProposedChange $change): bool => $change->kind === ChangeKind::Bought)
            ->map(fn (ProposedChange $change): string => mb_strtolower($change->ingredient));

        return ShoppingListItem::query()
            ->toBuy()
            ->with('ingredient')
            ->get()
            ->filter(fn (ShoppingListItem $entry): bool => in_array($entry->id, $ids, true)
                || $bought->contains(mb_strtolower($entry->ingredient->name ?? $entry->name)))
            ->modelKeys();
    }
}
