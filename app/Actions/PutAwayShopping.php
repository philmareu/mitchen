<?php

namespace App\Actions;

use App\Enums\ChangeKind;
use App\Enums\IngredientCategory;
use App\Enums\StorageLocation;
use App\Models\InventoryItem;
use App\Models\ShoppingListItem;
use App\Support\ProposedChange;
use App\Support\PurchaseDetails;
use App\Support\Quantity;
use App\Support\UnreadableQuantity;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Ticked shopping list entries are in the cart. Putting them away records them in the
 * inventory and the purchase history, and takes them off the list.
 */
class PutAwayShopping
{
    public function __construct(private ApplyKitchenUpdate $apply) {}

    /**
     * What each entry would add to the inventory, keyed by entry id, for the cook to check.
     * Entries that aren't an ingredient you keep and sit in the "Other" aisle (paper towels)
     * have none: they just come off the list.
     *
     * @param  Collection<int, ShoppingListItem>  $entries
     * @return array<int, ProposedChange>
     */
    public function propose(Collection $entries): array
    {
        $entries->loadMissing('ingredient.inventoryItems');

        return $entries
            ->filter(fn (ShoppingListItem $entry): bool => $entry->ingredient !== null || $entry->category !== IngredientCategory::Other)
            ->mapWithKeys(fn (ShoppingListItem $entry): array => [$entry->id => new ProposedChange(
                kind: ChangeKind::Bought,
                ingredient: $entry->ingredient->name ?? $entry->name,
                quantity: $this->isReadable($entry->quantity) ? $entry->quantity : null,
                location: $this->location($entry),
                category: $entry->ingredient->category ?? $entry->category,
                fromNote: $this->isReadable($entry->quantity) ? '' : (string) $entry->quantity,
            )])
            ->all();
    }

    /**
     * @param  list<ProposedChange>  $changes  What the cook confirmed to put away.
     * @param  list<int>  $entries  The ticked entries the cook was shown. All of them come off the list.
     * @param  PurchaseDetails  $purchase  Where it was bought, for the purchase history.
     * @return list<string> What happened, one line per change, then one for anything just cleared.
     */
    public function handle(array $changes, array $entries, PurchaseDetails $purchase = new PurchaseDetails): array
    {
        return DB::transaction(function () use ($changes, $entries, $purchase): array {
            $outcomes = $this->apply->handle($changes, purchase: $purchase);

            $cleared = ShoppingListItem::query()->bought()->whereKey($entries)->orderBy('name')->get();

            if ($cleared->isNotEmpty()) {
                ShoppingListItem::query()->whereKey($cleared->modelKeys())->delete();

                $outcomes[] = 'Off the list: '.$cleared->pluck('name')->join(', ', ' and ');
            }

            return $outcomes;
        });
    }

    /**
     * Where the low or out item is (the new supply replaces it), else wherever it's kept, else
     * where things from that aisle usually go.
     */
    private function location(ShoppingListItem $entry): StorageLocation
    {
        $items = $entry->ingredient?->inventoryItems->sortByDesc('updated_at');

        $item = $items?->first(fn (InventoryItem $item): bool => $item->quantity->needsRestock()) ?? $items?->first();

        return $item->location ?? ($entry->ingredient->category ?? $entry->category)->usualLocation();
    }

    private function isReadable(?string $quantity): bool
    {
        try {
            Quantity::parse($quantity);

            return true;
        } catch (UnreadableQuantity) {
            return false;
        }
    }
}
