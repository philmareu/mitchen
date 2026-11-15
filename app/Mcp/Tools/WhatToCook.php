<?php

namespace App\Mcp\Tools;

use App\Enums\Course;
use App\Enums\Diet;
use App\Enums\Protein;
use App\Enums\Readiness;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Support\RecipeShortfall;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('The cook\'s recipes by how close the kitchen is to making them, closest first: ready (everything\'s here), quick_shop (up to 3 things from any store), big_shop, plan_ahead (something hard to find, out of season or made ahead) or needs_equipment. Each says what\'s missing, what of that isn\'t on the shopping list yet (to_buy), the variant that gets closest when that isn\'t the recipe as written (made_as), and when it was last made. Within a group, never made and longest ago come first. Each also has its cuisine, course, main protein, diets and tags; filter by any of them ("something Italian I can make tonight": cuisine Italian, readiness ready). cuisines and tags list every one in use, for spelling new recipes the same way.')]
#[IsReadOnly]
class WhatToCook extends Tool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request): ResponseFactory
    {
        $validated = $request->validate([
            'readiness' => ['nullable', 'array'],
            'readiness.*' => [Rule::enum(Readiness::class)],
            'cuisine' => ['nullable', 'string', 'max:100'],
            'course' => ['nullable', 'array'],
            'course.*' => [Rule::enum(Course::class)],
            'protein' => ['nullable', 'array'],
            'protein.*' => [Rule::enum(Protein::class)],
            'diets' => ['nullable', 'array'],
            'diets.*' => [Rule::enum(Diet::class)],
            'tag' => ['nullable', 'string', 'max:50'],
        ]);

        $wanted = array_map(fn (string $value): Readiness => Readiness::from($value), $validated['readiness'] ?? []);

        $recipes = Recipe::query()
            ->when(filled($validated['cuisine'] ?? null), fn ($query) => $query->whereCuisine($validated['cuisine']))
            ->when(filled($validated['course'] ?? null), fn ($query) => $query->whereCourse(...array_map(Course::from(...), $validated['course'])))
            ->when(filled($validated['protein'] ?? null), fn ($query) => $query->whereProtein(...array_map(Protein::from(...), $validated['protein'])))
            ->when(filled($validated['diets'] ?? null), fn ($query) => $query->whereDiet(...array_map(Diet::from(...), $validated['diets'])))
            ->when(filled($validated['tag'] ?? null), fn ($query) => $query->whereTag($validated['tag']))
            ->with([
                ...Recipe::KITCHEN,
                'ingredients.ingredient.shoppingListItems',
                'variants.ingredients.ingredient.shoppingListItems',
            ])
            ->withMax('cookLogs', 'cooked_on')
            ->get()
            ->map(fn (Recipe $recipe): array => [
                'recipe' => $recipe,
                'shortfall' => $recipe->closestShortfall(),
                // A date column comes back as "2026-10-20" or "2026-10-20 00:00:00" depending on the driver.
                'last_made' => ($cookedOn = $recipe->getAttribute('cook_logs_max_cooked_on')) === null ? null : substr((string) $cookedOn, 0, 10),
            ])
            ->filter(fn (array $suggestion): bool => $wanted === [] || in_array($suggestion['shortfall']->readiness(), $wanted, true))
            ->sortBy([
                fn (array $a, array $b): int => $a['shortfall']->readiness()->rank() <=> $b['shortfall']->readiness()->rank(),
                fn (array $a, array $b): int => $a['last_made'] <=> $b['last_made'],
                fn (array $a, array $b): int => strcasecmp($a['recipe']->name, $b['recipe']->name),
            ]);

        return Response::structured([
            'cuisines' => Recipe::query()->cuisines()->all(),
            'tags' => Recipe::query()->tagsInUse()->all(),
            'recipes' => $recipes->map(fn (array $suggestion): array => $this->present($suggestion['recipe'], $suggestion['shortfall'], $suggestion['last_made']))->values()->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Recipe $recipe, RecipeShortfall $shortfall, ?string $lastMade): array
    {
        return [
            'id' => $recipe->id,
            'name' => $recipe->name,
            'cuisine' => $recipe->cuisine,
            'course' => $recipe->course?->value,
            'protein' => $recipe->protein?->value,
            'diets' => $recipe->diets ?? [],
            'tags' => $recipe->tags ?? [],
            'readiness' => $shortfall->readiness()->value,
            'made_as' => $shortfall->variant?->name,
            'missing' => $shortfall->describe() ?: null,
            'to_buy' => $shortfall->toBuy()->map(fn (RecipeIngredient $line): string => $line->name)->values()->all(),
            'last_made' => $lastMade,
        ];
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'readiness' => $schema->array()
                ->items($schema->string()->enum(Readiness::class))
                ->description('Only recipes this close, such as ["ready", "quick_shop"].'),
            'cuisine' => $schema->string()
                ->description('Only this cuisine ("Italian"), ignoring case.'),
            'course' => $schema->array()
                ->items($schema->string()->enum(Course::class))
                ->description('Only these courses, such as ["side"].'),
            'protein' => $schema->array()
                ->items($schema->string()->enum(Protein::class))
                ->description('Only these main proteins, such as ["beef", "pork"].'),
            'diets' => $schema->array()
                ->items($schema->string()->enum(Diet::class))
                ->description('Only recipes that meet all of these.'),
            'tag' => $schema->string()
                ->description('Only recipes with this tag ("weeknight").'),
        ];
    }
}
