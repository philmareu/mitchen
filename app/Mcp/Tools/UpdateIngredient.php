<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\PresentsKitchen;
use App\Models\Ingredient;
use App\Support\Names;
use App\Support\Quantity;
use App\Support\UnreadableQuantity;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Description(<<<'TEXT'
    Change how a catalog ingredient is kept stocked, when the cook asks ("keep 10 lb of pinto beans on hand", "tell me when the rice is down to a pound"). Only what you pass changes.

    core makes it a staple the cook always keeps, which goes on the restock list when it runs down. restock_point is when it goes on that list. stock_target is how much to stock up to: the restock list and the shopping list then say how much to buy to be back at it. Both are for core ingredients only.
    TEXT)]
#[IsIdempotent]
class UpdateIngredient extends Tool
{
    use PresentsKitchen;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'ingredient' => ['required', 'string', 'max:255'],
            'core' => ['nullable', 'boolean'],
            'restock_point' => ['nullable', 'string', 'max:255'],
            'stock_target' => ['nullable', 'string', 'max:255'],
        ]);

        $name = trim($validated['ingredient']);
        $ingredient = Ingredient::query()->named($name)->with('inventoryItems')->first();

        if ($ingredient === null) {
            $close = Names::close($name, Ingredient::query()->orderBy('name')->pluck('name'));

            return Response::error("\"{$name}\" isn't in the catalog. ".($close === [] ? 'list-inventory lists what is.' : 'It has '.implode(', ', $close).'.'));
        }

        $newPoint = $validated['restock_point'] ?? null;
        $newTarget = $validated['stock_target'] ?? null;
        $isCore = isset($validated['core']) ? (bool) $validated['core'] : $ingredient->is_core;

        if (! $isCore && (filled($newPoint) || filled($newTarget))) {
            return Response::error("{$ingredient->name} isn't a core ingredient, so it's never on the restock list. Pass core: true to make it one. Nothing was saved.");
        }

        try {
            $point = filled($newPoint) ? Quantity::parse($newPoint) : $ingredient->restock_point;
            $target = filled($newTarget) ? Quantity::parse($newTarget) : $ingredient->stock_target;
        } catch (UnreadableQuantity $exception) {
            return Response::error($exception->getMessage().' Nothing was saved.');
        }

        if ($problem = Ingredient::restockPointProblem($point)) {
            return Response::error("Restock point \"{$point->format()}\": {$problem} Nothing was saved.");
        }

        if ($problem = Ingredient::stockTargetProblem($target, $point)) {
            return Response::error("Stock target \"{$target->format()}\": {$problem} Nothing was saved.");
        }

        $ingredient->update([
            'is_core' => $isCore,
            'restock_point' => $point,
            'stock_target' => $target,
        ]);

        return Response::structured([
            'ingredient' => $this->presentIngredient($ingredient),
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
            'ingredient' => $schema->string()
                ->description('The catalog name, from list-inventory.')
                ->required(),
            'core' => $schema->boolean()
                ->description('true for a staple the cook always keeps, false for a one-off.'),
            'restock_point' => $schema->string()
                ->description('When it goes on the restock list: an amount ("2 lb", "1 can", "1/4 jar") or a level ("about half", "low", "out"). "low" is what it is when none is set: low or out.'),
            'stock_target' => $schema->string()
                ->description('How much to stock up to, as an amount: "10 lb", "4 cans", "a dozen". "none" takes the target off.'),
        ];
    }
}
