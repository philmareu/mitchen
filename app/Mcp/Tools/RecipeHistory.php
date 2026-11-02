<?php

namespace App\Mcp\Tools;

use App\Models\Recipe;
use App\Models\RecipeVersion;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('A recipe\'s history, newest first: each change that made a new version, with when, why (the change note) and what changed. Version 1 is the recipe as first added. Pass version to see the whole recipe as it was then, to compare or to go back to it with update-recipe.')]
#[IsReadOnly]
class RecipeHistory extends Tool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'recipe_id' => ['required', 'integer'],
            'version' => ['nullable', 'integer'],
        ]);

        $recipe = Recipe::query()->with('versions')->find($validated['recipe_id']);

        if ($recipe === null) {
            return Response::error("Recipe {$validated['recipe_id']} was not found. what-to-cook lists them all.");
        }

        if (! isset($validated['version'])) {
            return Response::structured([
                'name' => $recipe->name,
                'current_version' => $recipe->version,
                'first_added' => $recipe->created_at?->toDateString(),
                'versions' => $recipe->versions->map(fn (RecipeVersion $version): array => [
                    'version' => $version->number,
                    'on' => $version->created_at?->toDateString(),
                    'why' => $version->note,
                    'changes' => $version->changes,
                ])->all(),
            ]);
        }

        $number = (int) $validated['version'];

        // A version's row keeps the recipe as it was before it, so version n is in row n + 1.
        $snapshot = $number === $recipe->version
            ? $recipe->snapshot()
            : $recipe->versions->firstWhere('number', $number + 1)?->previous;

        if ($snapshot === null || $number < 1) {
            return Response::error("{$recipe->name} has versions 1 to {$recipe->version}.");
        }

        return Response::structured([
            'name' => $recipe->name,
            'version' => $number,
            'current' => $number === $recipe->version,
            'recipe' => $snapshot,
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
                ->description('The recipe, by id from get-recipe.')
                ->required(),
            'version' => $schema->integer()
                ->min(1)
                ->description('Show the whole recipe as it was at this version.'),
        ];
    }
}
