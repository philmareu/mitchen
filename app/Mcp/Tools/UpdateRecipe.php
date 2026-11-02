<?php

namespace App\Mcp\Tools;

use App\Actions\ReadRecipe;
use App\Actions\UpdateRecipe as SaveRevision;
use App\Filament\Resources\Recipes\RecipeResource;
use App\Mcp\Tools\Concerns\TakesRecipes;
use App\Models\CookLog;
use App\Models\Recipe;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description(<<<'TEXT'
    Change a recipe and keep the old version in its history: work in the cook's next-time notes (waiting_notes from get-recipe), or make a change they ask for ("make it serve 6", "toast the spices first").

    Send the whole recipe as it should read next time, in the same shape as add-recipe, starting from get-recipe. Change only what the notes or the cook ask for; keep everything else exactly as it is: the same ingredients in the same order and wording, and the same substitutes, variants, equipment and steps. Turn vague notes into modest changes the cook can measure: "more salt" raises it by a quarter to a half, rounded to an easy amount (1 tsp to 1½ tsp); "less vinegar" lowers it by about a quarter (1 cup to ¾ cup); an amount the note gives is used exactly. When an amount or time changes, update the step that mentions it too. A note made while cooking a variant changes that variant's lines. A one-off mishap ("burned it, the phone rang") isn't a change.

    By default this only previews: it returns exactly what would change ("Kosher salt: 1 tsp → 1½ tsp") and saves nothing. Show the cook that list, then call again with preview: false once they agree, passing the cook_log_ids of the notes it works in (so they stop waiting) and a change_note for the history.
    TEXT)]
class UpdateRecipe extends Tool
{
    use TakesRecipes;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request, ReadRecipe $read, SaveRevision $save): Response|ResponseFactory
    {
        $validated = $request->validate([
            'recipe_id' => ['required', 'integer'],
            ...$this->recipeRules(),
            'change_note' => ['nullable', 'string', 'max:5000'],
            'cook_log_ids' => ['nullable', 'array'],
            'cook_log_ids.*' => ['integer'],
            'preview' => ['nullable', 'boolean'],
        ]);

        $recipe = Recipe::query()->find($validated['recipe_id']);

        if ($recipe === null) {
            return Response::error("Recipe {$validated['recipe_id']} was not found. what-to-cook lists them all.");
        }

        if ($other = Recipe::query()->named($validated['name'])->whereKeyNot($recipe->id)->first()) {
            return Response::error("There's already another recipe called {$other->name} (id {$other->id}). Nothing was saved.");
        }

        $draft = $this->draft($validated, $read);

        if (is_string($draft)) {
            return Response::error($draft);
        }

        $changes = $save->preview($recipe, $draft);

        if ($validated['preview'] ?? true) {
            return Response::structured([
                'saved' => false,
                'changes' => $changes,
            ]);
        }

        $save->handle($recipe, $draft, $validated['change_note'] ?? null, array_map(intval(...), $validated['cook_log_ids'] ?? []));

        return Response::structured([
            'saved' => true,
            'changes' => $changes,
            'version' => $recipe->version,
            'url' => RecipeResource::getUrl('view', ['record' => $recipe]),
            'waiting_notes' => $recipe->waitingNotes()->map(fn (CookLog $log): string => $log->next_time)->all(),
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
            'recipe_id' => $schema->integer()
                ->description('The recipe to change, by id from get-recipe.')
                ->required(),
            ...$this->recipeSchema($schema),
            'change_note' => $schema->string()
                ->description('For the history: what changed and why, in a sentence or two ("More salt and a little less vinegar: the October batch was too sharp.").'),
            'cook_log_ids' => $schema->array()
                ->items($schema->integer())
                ->description('The cook log entries whose next-time notes this works in.'),
            'preview' => $schema->boolean()
                ->default(true)
                ->description('true (the default) returns what would change without saving; false saves it.'),
        ];
    }
}
