<?php

namespace App\Mcp\Tools;

use App\Actions\AddRecipe as SaveRecipe;
use App\Actions\ReadRecipe;
use App\Filament\Resources\Recipes\RecipeResource;
use App\Mcp\Tools\Concerns\TakesRecipes;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\RecipeEquipment;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description(<<<'TEXT'
    Save a new recipe to the cook's collection: from a web page, a recipe they pasted or typed, a photo of a card or cookbook page, or one you worked out together. Read it back first (name, servings, ingredients, equipment, steps) and only call this once the cook agrees.

    Copy the recipe as written: keep every amount, temperature and time, and never add ingredients or steps it doesn't have. Ingredients take the catalog's name when it has the same thing (list-inventory: "flour" is "All-purpose flour" if that's what it's called, but masa harina is not cornmeal); anything new is added to the catalog as a one-off ingredient in its aisle (category). When a new name is close to a catalog one ("White onion" with "Yellow onion" listed), nothing is saved: check with the cook, then use the catalog name or confirm it in new_ingredients. Equipment is what the recipe needs that not every kitchen has (slow cooker, Dutch oven, tortilla press), in the kitchen's names from list-equipment ("Blender" is the "Vitamix" if that's what it's listed as); leave out knives, bowls, spoons and an ordinary pot or pan. Equipment the kitchen hasn't got by a name close to a piece it has stops the save the same way: confirm it in equipment_not_in_kitchen.

    Alternatives the recipe gives for one ingredient go in substitutes ("or 1 cup milk + 1 tbsp lemon juice"); named ways of making it, chosen up front, go in variants ("Hot: add 2 habaneros", "Vegetarian: no bacon"). Describe it with cuisine, course, main protein and diets where they clearly apply, reusing the cuisines already in use (what-to-cook lists them); tags only when the cook gives them. Returns the recipe's id and link, what was new to the catalog, and how close the kitchen is to making it.
    TEXT)]
class AddRecipe extends Tool
{
    use TakesRecipes;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request, ReadRecipe $read, SaveRecipe $save): Response|ResponseFactory
    {
        $validated = $request->validate($this->recipeRules());

        if ($existing = Recipe::query()->named($validated['name'])->first()) {
            return Response::error("There's already a recipe called {$existing->name} (id {$existing->id}). Give this one another name, or use get-recipe to see that one.");
        }

        $draft = $this->draft($validated, $read);

        if (is_string($draft)) {
            return Response::error($draft);
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
        return $this->recipeSchema($schema);
    }
}
