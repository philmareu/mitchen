<?php

namespace App\Mcp\Tools;

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

#[Description('The cook\'s recipes by how close the kitchen is to making them, closest first: ready (everything\'s here), quick_shop (up to 3 things from any store), big_shop, plan_ahead (something hard to find, out of season or made ahead) or needs_equipment. Each says what\'s missing, what of that isn\'t on the shopping list yet (to_buy), the variant that gets closest when that isn\'t the recipe as written (made_as), and when it was last made. Within a group, never made and longest ago come first.')]
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
        ]);

        $wanted = array_map(fn (string $value): Readiness => Readiness::from($value), $validated['readiness'] ?? []);

        $recipes = Recipe::query()
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
        ];
    }
}
