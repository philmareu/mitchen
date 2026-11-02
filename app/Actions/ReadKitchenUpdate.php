<?php

namespace App\Actions;

use App\Ai\Agents\KitchenUpdateReader;
use App\Ai\Agents\PhotoReader;
use App\Enums\ChangeKind;
use App\Enums\IngredientCategory;
use App\Enums\StockLevel;
use App\Enums\StorageLocation;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\ShoppingListItem;
use App\Models\Store;
use App\Support\KitchenUpdate;
use App\Support\Names;
use App\Support\ProposedChange;
use App\Support\PurchaseDetails;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Str;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Files\LocalImage;
use Throwable;

/**
 * Asks the assistant what a quick update means, then checks its answer against the
 * real catalog: names are matched to existing ingredients and stores, ids it invents
 * (items, shopping list entries) are dropped, and so are prices that aren't numbers.
 */
class ReadKitchenUpdate
{
    /**
     * @param  list<string>  $photos  Paths to photos of a receipt, a shelf, the fridge or the pantry.
     */
    public function handle(string $note, array $photos = []): KitchenUpdate
    {
        $ingredients = Ingredient::query()->with('inventoryItems')->orderBy('name')->get();
        $shoppingList = ShoppingListItem::query()->toBuy()->with('ingredient')->orderBy('name')->get();
        $stores = Store::query()->orderBy('name')->pluck('name');

        $agent = $photos === [] ? KitchenUpdateReader::make() : PhotoReader::make();

        $response = $agent->prompt(
            $this->prompt($note, $ingredients, $shoppingList, $stores),
            attachments: array_map(fn (string $path): LocalImage => Image::fromPath($path), $photos),
        );

        $catalog = $ingredients->keyBy('id');
        $items = $ingredients->flatMap->inventoryItems->keyBy('id');

        $changes = collect($this->list($response['changes'] ?? null))
            ->map(fn (mixed $change): ?ProposedChange => is_array($change) ? $this->change($change, $catalog, $items) : null)
            ->filter()
            ->values()
            ->all();

        $notUnderstood = collect($this->list($response['not_understood'] ?? null))
            ->filter(fn (mixed $text): bool => is_string($text) && trim($text) !== '')
            ->map(fn (string $text): string => trim($text))
            ->values()
            ->all();

        return new KitchenUpdate(
            $changes,
            $notUnderstood,
            $this->boughtFromList($response['bought_from_list'] ?? null, $changes, $shoppingList),
            $this->notSeen($response['not_seen'] ?? null, $changes, $catalog, $items),
            $this->purchase($response['purchase'] ?? null, $stores),
        );
    }

    /**
     * @param  array<string, mixed>  $change
     * @param  BaseCollection<int, Ingredient>  $catalog  By id.
     * @param  BaseCollection<int, InventoryItem>  $items
     */
    private function change(array $change, BaseCollection $catalog, BaseCollection $items): ?ProposedChange
    {
        $kind = ChangeKind::tryFrom((string) ($change['kind'] ?? ''));
        $name = trim((string) ($change['ingredient'] ?? ''));

        if ($kind === null || $name === '') {
            return null;
        }

        $ingredient = $this->inCatalog($name, $catalog);

        $item = in_array($kind, [ChangeKind::Bought, ChangeKind::Need], true) ? null : $items->get((int) ($change['inventory_item_id'] ?? 0));

        if ($item !== null && $item->ingredient_id !== $ingredient?->id) {
            $item = null;
        }

        return new ProposedChange(
            kind: $kind,
            ingredient: $ingredient->name ?? Str::ucfirst($name),
            quantity: $kind === ChangeKind::UsedUp ? null : (filled($change['quantity'] ?? null) ? trim((string) $change['quantity']) : null),
            location: $item->location ?? StorageLocation::tryFrom((string) ($change['location'] ?? '')) ?? StorageLocation::Pantry,
            inventoryItemId: $item?->id,
            category: $ingredient->category ?? IngredientCategory::tryFrom((string) ($change['category'] ?? '')) ?? IngredientCategory::Other,
            expiresOn: $this->date($change['expires_on'] ?? null),
            fromNote: trim((string) ($change['from_note'] ?? '')),
            price: $kind === ChangeKind::Bought ? $this->money($change['price'] ?? null) : null,
        );
    }

    /**
     * Where and when it was all bought: a known store keeps its saved spelling, and a date in
     * the future is ignored.
     *
     * @param  BaseCollection<int, string>  $stores
     */
    private function purchase(mixed $purchase, BaseCollection $stores): PurchaseDetails
    {
        if (! is_array($purchase)) {
            return new PurchaseDetails;
        }

        $store = is_string($purchase['store'] ?? null) ? trim($purchase['store']) : '';
        $date = $this->date($purchase['purchased_on'] ?? null);

        return new PurchaseDetails(
            store: $store === '' ? null : $stores->first(fn (string $name): bool => mb_strtolower($name) === mb_strtolower($store), $store),
            purchasedOn: $date?->isFuture() ? null : $date,
            total: $this->money($purchase['total'] ?? null),
        );
    }

    private function money(mixed $value): ?float
    {
        return is_numeric($value) && $value >= 0 ? round((float) $value, 2) : null;
    }

    /**
     * Shopping list entries the assistant says were bought, plus any whose ingredient this update buys.
     *
     * @param  list<ProposedChange>  $changes
     * @param  Collection<int, ShoppingListItem>  $shoppingList
     * @return list<int>
     */
    private function boughtFromList(mixed $ids, array $changes, Collection $shoppingList): array
    {
        $ids = $this->ids($ids);

        $bought = collect($changes)
            ->filter(fn (ProposedChange $change): bool => $change->kind === ChangeKind::Bought)
            ->map(fn (ProposedChange $change): string => $change->ingredient);

        return $shoppingList
            ->filter(fn (ShoppingListItem $entry): bool => in_array($entry->id, $ids, true)
                || $bought->contains(fn (string $name): bool => Names::same($name, $entry->ingredient->name ?? $entry->name)))
            ->map(fn (ShoppingListItem $entry): int => $entry->id)
            ->values()
            ->all();
    }

    /**
     * Items a stock-take photo should have shown but didn't: real, not already out, and not
     * the subject of any change in this update.
     *
     * @param  list<ProposedChange>  $changes
     * @param  BaseCollection<int, Ingredient>  $catalog  By id.
     * @param  BaseCollection<int, InventoryItem>  $items
     * @return list<int>
     */
    private function notSeen(mixed $ids, array $changes, BaseCollection $catalog, BaseCollection $items): array
    {
        $changed = collect($changes)
            ->map(fn (ProposedChange $change): ?int => $this->inCatalog($change->ingredient, $catalog)?->id)
            ->filter();

        return collect($this->ids($ids))
            ->map(fn (int $id): ?InventoryItem => $items->get($id))
            ->filter(fn (?InventoryItem $item): bool => $item !== null
                && $item->stock_level !== StockLevel::Out
                && ! $changed->contains($item->ingredient_id))
            ->map(fn (InventoryItem $item): int => $item->id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The model's output is untrusted: anything that isn't a list counts as empty.
     *
     * @return list<mixed>
     */
    private function list(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    /**
     * @return list<int>
     */
    private function ids(mixed $value): array
    {
        return collect($this->list($value))
            ->filter(fn (mixed $id): bool => is_int($id) || (is_string($id) && ctype_digit($id)))
            ->map(fn (int|string $id): int => (int) $id)
            ->values()
            ->all();
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (blank($value)) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d', (string) $value)?->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The catalog ingredient of that name, matched like IngredientBuilder::named().
     *
     * @param  BaseCollection<int, Ingredient>  $catalog  By id.
     */
    private function inCatalog(string $name, BaseCollection $catalog): ?Ingredient
    {
        $id = Names::search($name, $catalog->map(fn (Ingredient $ingredient): string => $ingredient->name));

        return $id === null ? null : $catalog->get($id);
    }

    /**
     * @param  Collection<int, Ingredient>  $ingredients
     * @param  Collection<int, ShoppingListItem>  $shoppingList
     * @param  BaseCollection<int, string>  $stores
     */
    private function prompt(string $note, Collection $ingredients, Collection $shoppingList, BaseCollection $stores): string
    {
        $catalog = $ingredients->map(function (Ingredient $ingredient): string {
            $items = $ingredient->inventoryItems->map(fn (InventoryItem $item): string => sprintf(
                'item %d: %s in %s%s',
                $item->id,
                $item->quantity->format() ?: 'some',
                $item->location->value,
                $item->expires_on ? ', use by '.$item->expires_on->toDateString() : '',
            ));

            $tags = collect([
                $ingredient->is_core ? 'core' : null,
                $ingredient->is_core && ! $ingredient->restock_point->isUnknown() ? 'restock at '.$ingredient->restock_point->format() : null,
            ])->filter()->join(', ');

            return sprintf(
                '- %s%s: %s',
                $ingredient->name,
                $tags === '' ? '' : " [{$tags}]",
                $items->isEmpty() ? 'nothing on hand' : $items->join('; '),
            );
        })->join("\n") ?: '(the catalog is empty)';

        $list = $shoppingList
            ->map(fn (ShoppingListItem $entry): string => "- list {$entry->id}: {$entry->name}".($entry->quantity ? ", {$entry->quantity}" : ''))
            ->join("\n") ?: '(nothing on the list)';

        $storeNames = $stores->join(', ') ?: '(none saved yet)';
        $today = today()->format('l, F j, Y');
        $note = trim($note) === '' ? '(no note, just the photos)' : $note;

        return <<<TEXT
        Today is {$today}.

        The cook's note:
        """
        {$note}
        """

        Catalog and what's on hand:
        {$catalog}

        Shopping list, still to buy:
        {$list}

        The cook's stores:
        {$storeNames}
        TEXT;
    }
}
