<?php

namespace App\Mcp\Tools\Concerns;

use App\Models\Ingredient;
use App\Support\Names;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

/**
 * Stops a tool before it adds an ingredient to the catalog under a name close to one already
 * there ("White onion" with "Yellow onion" listed, "cilantor" for "Cilantro"), so the assistant
 * checks with the cook instead of the kitchen quietly gaining a one-off that's always out.
 * Names the cook confirmed are new go in new_ingredients.
 */
trait ChecksNewIngredients
{
    /**
     * @return array<string, mixed>
     */
    protected function newIngredientRules(): array
    {
        return [
            'new_ingredients' => ['nullable', 'array', 'max:100'],
            'new_ingredients.*' => ['string', 'max:255'],
        ];
    }

    protected function newIngredientsSchema(JsonSchema $schema): Type
    {
        return $schema->array()
            ->items($schema->string())
            ->description('Names that aren\'t in the catalog and that the cook confirmed are new, not another name for something it has. Only needed after this tool asks.');
    }

    /**
     * Why nothing can be saved yet, or null: names new to the catalog that are close to ones in
     * it and that the cook hasn't confirmed.
     *
     * @param  iterable<string>  $names
     * @param  list<string>  $confirmed
     */
    protected function newIngredientsToCheck(iterable $names, array $confirmed): ?string
    {
        $catalog = Ingredient::query()->orderBy('name')->pluck('name');
        $confirmed = collect($confirmed);

        $toCheck = collect($names)
            ->map(fn (string $name): string => trim($name))
            ->unique(fn (string $name): string => mb_strtolower($name))
            ->reject(fn (string $name): bool => $name === '' || Names::search($name, $catalog) !== null || Names::search($name, $confirmed) !== null)
            ->mapWithKeys(fn (string $name): array => [$name => Names::close($name, $catalog)])
            ->filter();

        if ($toCheck->isEmpty()) {
            return null;
        }

        $list = $toCheck->map(fn (array $close, string $name): string => "{$name} (the catalog has ".implode(', ', $close).')')->join('; ');

        return "Nothing was saved. These aren't in the catalog, but it has ingredients with close names: {$list}. Ask the cook whether each is one they already have. Use the catalog name if it is; if it's really something new, list it in new_ingredients. Then call again.";
    }
}
