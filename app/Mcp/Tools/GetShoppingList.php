<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\PresentsKitchen;
use App\Models\ShoppingListItem;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('The shopping list, by aisle. to_buy is what\'s still to get, each with how much, what\'s at home already and what the cook last paid for it. in_cart is what was ticked off in the store and not put away yet. Ids are for update-kitchen (shopping_list_ids) and remove-from-shopping-list.')]
#[IsReadOnly]
class GetShoppingList extends Tool
{
    use PresentsKitchen;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): ResponseFactory
    {
        $entries = ShoppingListItem::query()
            ->with(['ingredient.inventoryItems', 'ingredient.lastPaid.purchase.store'])
            ->orderBy('category')
            ->orderBy('name')
            ->get();

        [$inCart, $toBuy] = $entries->partition(fn (ShoppingListItem $entry): bool => $entry->bought_at !== null);

        return Response::structured([
            'to_buy' => $toBuy->map(fn (ShoppingListItem $entry): array => [
                ...$this->presentEntry($entry),
                'at_home' => $entry->ingredient?->inventoryItems->map($this->presentItem(...))->values()->all() ?? [],
                'last_paid' => ($lastPaid = $entry->ingredient?->lastPaid)
                    ? "{$lastPaid->formattedPrice()} ({$lastPaid->whereAndWhen()})"
                    : null,
            ])->values()->all(),
            'in_cart' => $inCart->map($this->presentEntry(...))->values()->all(),
        ]);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
