<?php

namespace App\Mcp\Tools;

use App\Actions\AddRecipe as SaveRecipe;
use App\Actions\ReadRecipe;
use App\Enums\IngredientCategory;
use App\Filament\Resources\Recipes\RecipeResource;
use App\Models\Equipment;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\RecipeEquipment;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description(<<<'TEXT'
    Save a new recipe to the cook's collection: from a web page, a recipe they pasted or typed, a photo of a card or cookbook page, or one you worked out together. Read it back first (name, servings, ingredients, equipment, steps) and only call this once the cook agrees.

    Copy the recipe as written: keep every amount, temperature and time, and never add ingredients or steps it doesn't have. Ingredients take the catalog's name when it has the same thing (list-inventory: "flour" is "All-purpose flour" if that's what it's called, but masa harina is not cornmeal); anything new is added to the catalog as a one-off ingredient in its aisle (category). Equipment is what the recipe needs that not every kitchen has (slow cooker, Dutch oven, tortilla press), in the kitchen's names from list-equipment; leave out knives, bowls, spoons and an ordinary pot or pan.

    Alternatives the recipe gives for one ingredient go in substitutes ("or 1 cup milk + 1 tbsp lemon juice"); named ways of making it, chosen up front, go in variants ("Hot: add 2 habaneros", "Vegetarian: no bacon"). Returns the recipe's id and link, what was new to the catalog, and how close the kitchen is to making it.
    TEXT)]
class AddRecipe extends Tool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request, ReadRecipe $read, SaveRecipe $save): Response|ResponseFactory
    {
        $category = ['nullable', Rule::enum(IngredientCategory::class)];

        $validated = $request->validate([
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
            'variants.*.changes.*.leave_out' => ['nullable', 'boolean'],
            'variants.*.changes.*.category' => $category,
            'equipment' => ['nullable', 'array', 'max:50'],
            'equipment.*' => ['string', 'max:255'],
            'steps' => ['nullable', 'array', 'max:100'],
            'steps.*' => ['string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        if ($existing = Recipe::query()->named($validated['name'])->first()) {
            return Response::error("There's already a recipe called {$existing->name} (id {$existing->id}). Give this one another name, or use get-recipe to see that one.");
        }

        $draft = $read->tidy(
            $validated,
            Ingredient::query()->orderBy('name')->pluck('name'),
            Equipment::query()->orderBy('name')->pluck('name'),
        );

        if ($draft->isEmpty()) {
            return Response::error('A recipe needs ingredients or steps. Nothing was saved.');
        }

        if (count($draft->substitutes) < count($validated['substitutes'] ?? [])) {
            return Response::error('Each substitute\'s instead_of must name one of the recipe\'s ingredients exactly as written in ingredients. Nothing was saved.');
        }

        $lastIngredient = (int) Ingredient::query()->max('id');

        $recipe = Recipe::query()->with(Recipe::KITCHEN)->findOrFail($save->handle($draft)->id);
        $shortfall = $recipe->closestShortfall();

        return Response::structured([
            'id' => $recipe->id,
            'name' => $recipe->name,
            'url' => RecipeResource::getUrl('view', ['record' => $recipe]),
            'new_ingredients' => Ingredient::query()->where('id', '>', $lastIngredient)->orderBy('name')->pluck('name')->all(),
            'equipment_not_in_kitchen' => $recipe->equipment
                ->reject(fn (RecipeEquipment $piece): bool => $piece->isInKitchen())
                ->pluck('name')
                ->values()
                ->all(),
            'readiness' => $shortfall->readiness()->value,
            'made_as' => $shortfall->variant?->name,
            'missing' => $shortfall->describe() ?: null,
        ]);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
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
