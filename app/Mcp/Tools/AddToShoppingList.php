<?php

namespace App\Mcp\Tools;

use App\Actions\AddToShoppingList as AddToList;
use App\Enums\IngredientCategory;
use App\Mcp\Tools\Concerns\PresentsKitchen;
use App\Models\ShoppingListItem;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Description('Put things on the shopping list. Something already on it and not bought yet stays one entry, taking the new quantity when you give one. A catalog ingredient takes its catalog name and aisle; anything else (paper towels) is kept as written. Pass restock_list: true to add everything on the restock list that isn\'t on the shopping list yet.')]
#[IsIdempotent]
class AddToShoppingList extends Tool
{
    use PresentsKitchen;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request, AddToList $add): ResponseFactory
    {
        $validated = $request->validate([
            'items' => ['required_without:restock_list', 'nullable', 'array', 'max:100'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['nullable', 'string', 'max:255'],
            'items.*.category' => ['nullable', Rule::enum(IngredientCategory::class)],
            'restock_list' => ['nullable', 'boolean'],
        ]);

        $entries = DB::transaction(function () use ($validated, $add): array {
            $entries = array_map(fn (array $item): ShoppingListItem => $add->handle(
                $item['name'],
                $item['quantity'] ?? null,
                IngredientCategory::tryFrom((string) ($item['category'] ?? '')),
            ), $validated['items'] ?? []);

            if ($validated['restock_list'] ?? false) {
                $names = $add->restockList();

                array_push($entries, ...ShoppingListItem::query()->toBuy()->whereIn('name', $names)->get()->all());
            }

            return $entries;
        });

        return Response::structured([
            'on_the_list' => array_map($this->presentEntry(...), $entries),
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
            'items' => $schema->array()
                ->items($schema->object([
                    'name' => $schema->string()->description('The catalog name, or a plain name ("Paper towels").')->required(),
                    'quantity' => $schema->string()->description('How much to get, such as "2 lb" or "a dozen".'),
                    'category' => $schema->string()->enum(IngredientCategory::class)->description('The aisle, for something not in the catalog.'),
                ]))
                ->description('What to add.'),
            'restock_list' => $schema->boolean()
                ->description('true also adds everything on the restock list that isn\'t on the shopping list yet.'),
        ];
    }
}
