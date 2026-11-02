<?php

namespace App\Mcp\Tools;

use App\Models\ShoppingListItem;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Take entries off the shopping list without buying them, by id from get-shopping-list. To record that something was bought, use update-kitchen instead: it adds it to what\'s on hand and takes it off the list.')]
#[IsDestructive]
class RemoveFromShoppingList extends Tool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $entries = ShoppingListItem::query()->whereKey($validated['ids'])->orderBy('name')->get();

        if ($entries->isEmpty()) {
            return Response::error('None of those ids are on the shopping list.');
        }

        ShoppingListItem::query()->whereKey($entries->modelKeys())->delete();

        return Response::structured([
            'removed' => $entries->pluck('name')->all(),
        ]);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'ids' => $schema->array()
                ->items($schema->integer())
                ->min(1)
                ->description('The ids of the entries to remove.')
                ->required(),
        ];
    }
}
