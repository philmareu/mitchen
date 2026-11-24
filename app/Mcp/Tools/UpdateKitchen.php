<?php

namespace App\Mcp\Tools;

use App\Actions\ApplyKitchenUpdate;
use App\Actions\PrepareKitchenChanges;
use App\Enums\ChangeKind;
use App\Enums\IngredientCategory;
use App\Enums\StorageLocation;
use App\Mcp\Tools\Concerns\ChecksNewNames;
use App\Support\PurchaseDetails;
use App\Support\UnreadableQuantity;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description(<<<'TEXT'
    Save changes to what's on hand and the shopping list, all or nothing. Read the changes back to the cook first and only call this once they agree.

    Kinds: bought (new supply came in; quantity is how much), set (how much is left now: "half a bag", "low"), used_up (finished or thrown away) and need (put it on the shopping list; quantity is how much to get, and without one a core ingredient with a stock target gets what it takes to be back at it). Use catalog names from list-inventory; a new name is added as a one-off ingredient, except for need, which only adds a list entry. When a new name is close to a catalog one, nothing is saved: check with the cook, then use the catalog name or confirm it in new_ingredients.

    For set and used_up, pass the item's inventory_item_id when the ingredient is in more than one place. Without a location, a change goes where the ingredient already is, else where its aisle usually goes; a location moves the item there.

    Bought changes are saved to the purchase history (with purchase: store, date and total) and take the ingredient off the shopping list. List entries bought under another name go in shopping_list_ids. Returns one line per change saying what happened.
    TEXT)]
class UpdateKitchen extends Tool
{
    use ChecksNewNames;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request, PrepareKitchenChanges $prepare, ApplyKitchenUpdate $apply): Response|ResponseFactory
    {
        $validated = $request->validate([
            'changes' => ['required', 'array', 'min:1', 'max:100'],
            'changes.*.kind' => ['required', Rule::enum(ChangeKind::class)],
            'changes.*.ingredient' => ['required', 'string', 'max:255'],
            'changes.*.quantity' => ['nullable', 'string', 'max:255'],
            'changes.*.location' => ['nullable', Rule::enum(StorageLocation::class)],
            'changes.*.inventory_item_id' => ['nullable', 'integer'],
            'changes.*.category' => ['nullable', Rule::enum(IngredientCategory::class)],
            'changes.*.expires_on' => ['nullable', 'date_format:Y-m-d'],
            'changes.*.ready_on' => ['nullable', 'date_format:Y-m-d'],
            'changes.*.price' => ['nullable', 'numeric', 'min:0'],
            'shopping_list_ids' => ['nullable', 'array'],
            'shopping_list_ids.*' => ['integer'],
            'purchase' => ['nullable', 'array'],
            'purchase.store' => ['nullable', 'string', 'max:255'],
            'purchase.purchased_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'purchase.total' => ['nullable', 'numeric', 'min:0'],
            ...$this->newIngredientRules(),
        ]);

        // A need only adds a shopping list entry, never an ingredient.
        $adding = array_column(array_filter($validated['changes'], fn (array $change): bool => $change['kind'] !== ChangeKind::Need->value), 'ingredient');

        if ($check = $this->stopForNames($this->newIngredientsToCheck($adding, array_values($validated['new_ingredients'] ?? [])))) {
            return Response::error($check);
        }

        $update = $prepare->handle(
            array_values($validated['changes']),
            array_values(array_map(intval(...), $validated['shopping_list_ids'] ?? [])),
            PurchaseDetails::fromArray($validated['purchase'] ?? []),
        );

        try {
            $saved = $apply->handle($update->changes, $update->boughtFromList, $update->purchase);
        } catch (UnreadableQuantity $exception) {
            return Response::error($exception->getMessage().' Nothing was saved.');
        }

        return Response::structured([
            'saved' => $saved,
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
            'changes' => $schema->array()
                ->items($schema->object([
                    'kind' => $schema->string()->enum(ChangeKind::class)->required(),
                    'ingredient' => $schema->string()->description('The catalog name, or a plain name for something new ("Chicken thighs").')->required(),
                    'quantity' => $schema->string()->description('"2 lb", "half a jar", "3 cans", "6", "low". Leave out for used_up, and when not known.'),
                    'location' => $schema->string()->enum(StorageLocation::class),
                    'inventory_item_id' => $schema->integer()->description('The item a set or used_up change is about.'),
                    'category' => $schema->string()->enum(IngredientCategory::class)->description('The aisle, for something new.'),
                    'expires_on' => $schema->string()->format('date')->description('Use-by date, only when the cook gives one.'),
                    'ready_on' => $schema->string()->format('date')->description('For bought or set: when something here but not ready yet will be (hard avocados on the counter: about 4 days). Recipes plan ahead for it until then. Today when the cook says it\'s ripe now.'),
                    'price' => $schema->number()->min(0)->description('For bought: what it cost in total, when the cook says.'),
                ]))
                ->min(1)
                ->required(),
            'shopping_list_ids' => $schema->array()
                ->items($schema->integer())
                ->description('Shopping list entries (ids from get-shopping-list) this update bought, to take off the list.'),
            'purchase' => $schema->object([
                'store' => $schema->string()->description('Where it was bought.'),
                'purchased_on' => $schema->string()->format('date')->description('When, if not today.'),
                'total' => $schema->number()->min(0)->description('What the shop came to.'),
            ])->description('For bought changes: the shop they came from.'),
            'new_ingredients' => $this->newIngredientsSchema($schema),
        ];
    }
}
