<?php

namespace App\Actions;

use App\Enums\ChangeKind;
use App\Enums\StockLevel;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\ShoppingListItem;
use App\Support\Names;
use App\Support\ProposedChange;
use App\Support\PurchaseDetails;
use App\Support\Quantity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Saves changes to the kitchen the cook agreed to, all or nothing: an assistant's over the MCP
 * server (update-kitchen), or a shop being put away.
 */
class ApplyKitchenUpdate
{
    public function __construct(
        private RestockIngredient $restock,
        private UseUpInventoryItem $useUp,
        private AddToShoppingList $addToShoppingList,
        private RecordPurchase $recordPurchase,
    ) {}

    /**
     * @param  list<ProposedChange>  $changes
     * @param  list<int>  $boughtFromList  Shopping list entries the cook ticked off.
     * @param  PurchaseDetails  $purchase  Where and when anything bought was bought.
     * @return list<string> What happened, one line per change, then one each for the purchase history and the shopping list.
     */
    public function handle(array $changes, array $boughtFromList = [], PurchaseDetails $purchase = new PurchaseDetails): array
    {
        return DB::transaction(function () use ($changes, $boughtFromList, $purchase): array {
            $outcomes = array_map($this->apply(...), $changes);

            // After the changes, so ingredients they added are there to link the lines to.
            if ($recorded = $this->recordPurchase->handle($purchase, $changes)) {
                $summary = $recorded->summary();
                $outcomes[] = 'Saved to purchases'.($summary === '' ? '' : ": {$summary}");
            }

            $got = ShoppingListItem::query()->toBuy()->whereKey($boughtFromList)->orderBy('name')->get();

            if ($got->isNotEmpty()) {
                $outcomes[] = 'Shopping list: got '.$got->pluck('name')->join(', ', ' and ');
            }

            ShoppingListItem::query()->whereKey([...$got->modelKeys(), ...$this->inTheCart($changes)])->delete();

            return $outcomes;
        });
    }

    /**
     * Entries ticked off earlier (in the store) whose ingredient this update bought: they're in
     * the inventory now, so putting them away later would count them twice.
     *
     * @param  list<ProposedChange>  $changes
     * @return list<int>
     */
    private function inTheCart(array $changes): array
    {
        $bought = collect($changes)
            ->filter(fn (ProposedChange $change): bool => $change->kind === ChangeKind::Bought)
            ->map(fn (ProposedChange $change): string => $change->ingredient);

        if ($bought->isEmpty()) {
            return [];
        }

        return ShoppingListItem::query()
            ->bought()
            ->with('ingredient')
            ->get()
            ->filter(fn (ShoppingListItem $entry): bool => $bought->contains(fn (string $name): bool => Names::same($name, $entry->ingredient->name ?? $entry->name)))
            ->modelKeys();
    }

    private function apply(ProposedChange $change): string
    {
        if ($change->kind === ChangeKind::Need) {
            $entry = $this->addToShoppingList->handle($change->ingredient, $change->quantity, $change->category);

            return "{$entry->name}: on the shopping list";
        }

        $ingredient = $this->ingredient($change);
        $quantity = Quantity::parse($change->quantity);

        if ($change->kind === ChangeKind::UsedUp || $quantity->level === StockLevel::Out) {
            return $this->useUp($ingredient, $change);
        }

        if ($change->kind === ChangeKind::Bought) {
            $item = $this->restock->handle($ingredient, $quantity, $change->location, $change->readyOn);
        } else {
            $item = $this->itemToSet($ingredient, $change);
            $item->quantity = $quantity;

            // No date leaves it as it was; today or earlier means it's ripe now.
            if ($change->readyOn !== null) {
                $item->ripenUntil($change->readyOn);
            }
        }

        if ($change->expiresOn !== null) {
            $item->expires_on = Carbon::instance($change->expiresOn);
        }

        $item->save();

        return sprintf(
            '%s: %s %s (%s)',
            $ingredient->name,
            $change->kind === ChangeKind::Bought ? 'now' : 'set to',
            $item->quantity->format() ?: 'on hand',
            $item->location->getLabel(),
        );
    }

    private function useUp(Ingredient $ingredient, ProposedChange $change): string
    {
        $item = $this->item($ingredient, $change);

        if ($item === null) {
            return "{$ingredient->name}: nothing on hand to use up";
        }

        return $this->useUp->handle($item)
            ? "{$ingredient->name}: out, added to the restock list"
            : "{$ingredient->name}: used up and removed";
    }

    /**
     * The item a "now have" change is about: the one it names (moved if the cook changed where
     * it is), else the one in the same place, else a new one there. An item somewhere else is
     * left alone: a photo of the fridge says nothing about the jar in the pantry.
     */
    private function itemToSet(Ingredient $ingredient, ProposedChange $change): InventoryItem
    {
        $items = $ingredient->inventoryItems()->latest('updated_at')->get();

        $item = $items->firstWhere('id', $change->inventoryItemId)
            ?? $items->firstWhere('location', $change->location)
            ?? $ingredient->inventoryItems()->make();

        $item->location = $change->location;

        return $item;
    }

    /**
     * The item a change is about: the one it names, else one in the same place, else any.
     */
    private function item(Ingredient $ingredient, ProposedChange $change): ?InventoryItem
    {
        $items = $ingredient->inventoryItems()->latest('updated_at')->get();

        return $items->firstWhere('id', $change->inventoryItemId)
            ?? $items->firstWhere('location', $change->location)
            ?? $items->first();
    }

    private function ingredient(ProposedChange $change): Ingredient
    {
        return Ingredient::query()->named($change->ingredient)->first()
            ?? Ingredient::create([
                'name' => $change->ingredient,
                'category' => $change->category,
                'is_core' => false,
            ]);
    }
}
