<?php

namespace App\Mcp\Tools;

use App\Enums\StorageLocation;
use App\Mcp\Tools\Concerns\PresentsKitchen;
use App\Models\Builders\InventoryItemBuilder;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('The ingredient catalog with what\'s on hand: for each ingredient, every item in the kitchen with its id, quantity, place and use-by date (an ingredient with no items isn\'t stocked). Core ingredients are staples the cook always keeps: restock_point is when one goes on the restock list (null = when it\'s low or out) and stock_target how much the cook stocks up to (null = not set). Use these names and item ids in update-kitchen.')]
#[IsReadOnly]
class ListInventory extends Tool
{
    use PresentsKitchen;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): ResponseFactory
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', Rule::enum(StorageLocation::class)],
            'on_hand_only' => ['nullable', 'boolean'],
        ]);

        $location = StorageLocation::tryFrom((string) ($validated['location'] ?? ''));
        $onHandOnly = (bool) ($validated['on_hand_only'] ?? false);

        $ingredients = Ingredient::query()
            ->with('inventoryItems')
            ->when($validated['search'] ?? null, fn ($query, string $search) => $query->where('name', 'like', '%'.trim($search).'%'))
            ->when($location !== null || $onHandOnly, fn ($query) => $query->whereHas('inventoryItems', fn (InventoryItemBuilder $items) => $items
                ->when($location, fn (InventoryItemBuilder $items, StorageLocation $location) => $items->where('location', $location))
                ->when($onHandOnly, fn (InventoryItemBuilder $items) => $items->notOut())))
            ->orderBy('name')
            ->get();

        return Response::structured([
            'ingredients' => $ingredients->map(function (Ingredient $ingredient) use ($location): array {
                $presented = $this->presentIngredient($ingredient);

                if ($location !== null) {
                    $presented['on_hand'] = $ingredient->inventoryItems
                        ->filter(fn (InventoryItem $item): bool => $item->location === $location)
                        ->map($this->presentItem(...))
                        ->values()
                        ->all();
                }

                return $presented;
            })->all(),
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
            'search' => $schema->string()
                ->description('Only ingredients whose name contains this, such as "rice".'),
            'location' => $schema->string()
                ->enum(StorageLocation::class)
                ->description('Only ingredients with something in this place, showing just those items.'),
            'on_hand_only' => $schema->boolean()
                ->description('true leaves out ingredients that are out or not stocked.'),
        ];
    }
}
