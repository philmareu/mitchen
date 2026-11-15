<?php

namespace App\Mcp\Tools\Concerns;

use App\Actions\ReadRecipe;
use App\Enums\IngredientCategory;
use App\Models\Equipment;
use App\Models\Ingredient;
use App\Support\RecipeDraft;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;

/**
 * A whole recipe as an assistant sends it (add-recipe, update-recipe): the RecipeReader's
 * shape, so ReadRecipe::tidy() checks it against the catalog like a recipe read from a card.
 */
trait TakesRecipes
{
    /**
     * @return array<string, mixed>
     */
    protected function recipeRules(): array
    {
        $category = ['nullable', Rule::enum(IngredientCategory::class)];

        return [
            'name' => ['required', 'string', 'max:255'],
            'source' => ['nullable', 'string', 'max:255'],
            'servings' => ['nullable', 'integer', 'min:1', 'max:999'],
            'ingredients' => ['nullable', 'array', 'max:100'],
            'ingredients.*.name' => ['required', 'string', 'max:255'],
            'ingredients.*.quantity' => ['nullable', 'string', 'max:100'],
            'ingredients.*.note' => ['nullable', 'string', 'max:255'],
            'ingredients.*.optional' => ['nullable', 'boolean'],
            'ingredients.*.category' => $category,
            'substitutes' => ['nullable', 'array', 'max:50'],
            'substitutes.*.instead_of' => ['required', 'string', 'max:255'],
            'substitutes.*.use' => ['required', 'array', 'min:1', 'max:20'],
            'substitutes.*.use.*.name' => ['required', 'string', 'max:255'],
            'substitutes.*.use.*.quantity' => ['nullable', 'string', 'max:100'],
            'substitutes.*.use.*.category' => $category,
            'substitutes.*.note' => ['nullable', 'string', 'max:255'],
            'variants' => ['nullable', 'array', 'max:20'],
            'variants.*.name' => ['required', 'string', 'max:255'],
            'variants.*.notes' => ['nullable', 'string', 'max:5000'],
            'variants.*.changes' => ['nullable', 'array', 'max:50'],
            'variants.*.changes.*.name' => ['required', 'string', 'max:255'],
            'variants.*.changes.*.quantity' => ['nullable', 'string', 'max:100'],
            'variants.*.changes.*.note' => ['nullable', 'string', 'max:255'],
            'variants.*.changes.*.optional' => ['nullable', 'boolean'],
            'variants.*.changes.*.leave_out' => ['nullable', 'boolean'],
            'variants.*.changes.*.category' => $category,
            'equipment' => ['nullable', 'array', 'max:50'],
            'equipment.*' => ['string', 'max:255'],
            'steps' => ['nullable', 'array', 'max:100'],
            'steps.*' => ['string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * The validated recipe as a draft, or why it can't be saved.
     *
     * @param  array<string, mixed>  $validated
     */
    protected function draft(array $validated, ReadRecipe $read): RecipeDraft|string
    {
        $draft = $read->tidy(
            $validated,
            Ingredient::query()->orderBy('name')->pluck('name'),
            Equipment::query()->orderBy('name')->pluck('name'),
        );

        return match (true) {
            $draft->isEmpty() => 'A recipe needs ingredients or steps. Nothing was saved.',
            count($draft->substitutes) < count($validated['substitutes'] ?? []) => 'Each substitute\'s instead_of must name one of the recipe\'s ingredients exactly as written in ingredients. Nothing was saved.',
            default => $draft,
        };
    }

    /**
     * @return array<string, Type>
     */
    protected function recipeSchema(JsonSchema $schema): array
    {
        $quantity = fn () => $schema->string()->description('As the recipe says it: "2 cups", "1½ tsp", "1 (15 oz) can", "a pinch", "to taste". Leave out when there\'s no amount.');
        $name = fn () => $schema->string()->description('The ingredient alone, in sentence case ("Yellow onion"), without the amount or preparation.')->required();
        $category = fn () => $schema->string()->enum(IngredientCategory::class)->description('The aisle, for an ingredient new to the catalog.');

        return [
            'name' => $schema->string()
                ->description('The recipe\'s name as written, else a short plain name for the dish.')
                ->required(),
            'source' => $schema->string()
                ->description('Where it\'s from: the web page\'s URL, a book, a person ("Grandma Rose").'),
            'servings' => $schema->integer()
                ->min(1)
                ->description('How many it serves or makes ("makes 2 dozen" is 24). Leave out when it doesn\'t say.'),
            'ingredients' => $schema->array()
                ->items($schema->object([
                    'quantity' => $quantity(),
                    'name' => $name(),
                    'note' => $schema->string()->description('Preparation or size: "diced", "softened", "large".'),
                    'optional' => $schema->boolean()->description('true when the recipe says it\'s optional or for garnish.'),
                    'category' => $category(),
                ]))
                ->description('In the recipe\'s order.'),
            'substitutes' => $schema->array()
                ->items($schema->object([
                    'instead_of' => $schema->string()->description('The ingredient it replaces, named exactly as in ingredients.')->required(),
                    'use' => $schema->array()->items($schema->object([
                        'quantity' => $quantity(),
                        'name' => $name(),
                        'category' => $category(),
                    ]))->required(),
                    'note' => $schema->string()->description('How to use it: "stir and let it sit 5 minutes".'),
                ]))
                ->description('Only alternatives the recipe gives.'),
            'variants' => $schema->array()
                ->items($schema->object([
                    'name' => $schema->string()->description('Short, as the recipe calls it: "Hot", "Blueberry".')->required(),
                    'notes' => $schema->string()->description('Anything else it changes, such as in the method.'),
                    'changes' => $schema->array()->items($schema->object([
                        'quantity' => $quantity(),
                        'name' => $schema->string()->description('An ingredient the recipe has, named exactly as in ingredients, or a new one.')->required(),
                        'note' => $schema->string()->description('Preparation or size: "diced", "seeded".'),
                        'optional' => $schema->boolean()->description('true when it\'s optional in this variant.'),
                        'leave_out' => $schema->boolean()->description('true when this variant goes without that ingredient.'),
                        'category' => $category(),
                    ])),
                ])),
            'equipment' => $schema->array()
                ->items($schema->string())
                ->description('Equipment not every kitchen has, in the kitchen\'s names when it has them.'),
            'steps' => $schema->array()
                ->items($schema->string())
                ->description('The method, one step per entry, in the recipe\'s own words.'),
            'notes' => $schema->string()
                ->description('Tips that aren\'t steps: "Freezes well".'),
        ];
    }
}
