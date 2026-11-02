<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\PresentsKitchen;
use App\Models\Ingredient;
use App\Models\ShoppingListItem;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Core ingredients to restock: low, out, down to their restock point, or not stocked at all. Each shows what\'s left and whether it\'s on the shopping list: "to_buy", "in_cart" (ticked off, not put away yet) or null. add-to-shopping-list with restock_list: true adds the ones that aren\'t.')]
#[IsReadOnly]
class RestockList extends Tool
{
    use PresentsKitchen;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): ResponseFactory
    {
        $ingredients = Ingredient::query()
            ->needsRestock()
            ->with(['inventoryItems', 'shoppingListItems'])
            ->orderBy('name')
            ->get();

        return Response::structured([
            'ingredients' => $ingredients->map(fn (Ingredient $ingredient): array => [
                ...$this->presentIngredient($ingredient),
                'shopping_list' => match (true) {
                    $ingredient->shoppingListItems->contains(fn (ShoppingListItem $entry): bool => $entry->bought_at === null) => 'to_buy',
                    $ingredient->isOnShoppingList() => 'in_cart',
                    default => null,
                },
            ])->all(),
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
