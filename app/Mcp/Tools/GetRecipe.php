<?php

namespace App\Mcp\Tools;

use App\Filament\Resources\Recipes\RecipeResource;
use App\Models\CookLog;
use App\Models\Recipe;
use App\Models\RecipeEquipment;
use App\Models\RecipeIngredient;
use App\Models\RecipeSubstitute;
use App\Models\RecipeVariant;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('One recipe, by id (from what-to-cook) or name: its cuisine, course, main protein, diets and tags, its ingredients with what\'s on hand for each (on_hand, low, ripening with the date it\'s ready, or out; where it is, and a substitute on hand when it\'s out), substitutes (their ingredients as written, in update-recipe\'s shape, and a summary scaled like the ingredients), variants with their changes (as written, in update-recipe\'s shape), equipment and whether the kitchen has it, steps, the latest cook log entries, the next-time notes waiting to be worked in (update-recipe), and a link to its page in Mitchen. Pass variant to make it a variant\'s way, and scale to halve or double it: amounts Mitchen can read are scaled, "a pinch" stays a pinch.')]
#[IsReadOnly]
class GetRecipe extends Tool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'recipe_id' => ['required_without:name', 'nullable', 'integer'],
            'name' => ['required_without:recipe_id', 'nullable', 'string', 'max:255'],
            'variant' => ['nullable', 'string', 'max:255'],
            'scale' => ['nullable', 'numeric', 'min:0.1', 'max:10'],
        ]);

        $recipes = Recipe::query()
            ->with([...Recipe::KITCHEN, 'ingredients.ingredient.shoppingListItems', 'variants.ingredients.ingredient.shoppingListItems'])
            ->when(
                isset($validated['recipe_id']),
                fn ($query) => $query->whereKey($validated['recipe_id']),
                fn ($query) => $query->where('name', 'like', '%'.trim($validated['name']).'%'),
            )
            ->orderBy('name')
            ->get();

        $recipe = $recipes->count() === 1
            ? $recipes->first()
            : $recipes->first(fn (Recipe $recipe): bool => mb_strtolower($recipe->name) === mb_strtolower(trim($validated['name'] ?? '')));

        if ($recipe === null) {
            return Response::error($recipes->isEmpty()
                ? 'No recipe matches that. what-to-cook lists them all.'
                : 'Several recipes match: '.$recipes->map(fn (Recipe $recipe): string => "{$recipe->name} (id {$recipe->id})")->join(', ').'.');
        }

        $variant = null;

        if (filled($validated['variant'] ?? null)) {
            $variant = $recipe->variants->first(fn (RecipeVariant $variant): bool => mb_strtolower($variant->name) === mb_strtolower(trim($validated['variant'])));

            if ($variant === null) {
                return Response::error($recipe->variants->isEmpty()
                    ? "{$recipe->name} has no variants."
                    : "{$recipe->name} has these variants: ".$recipe->variants->pluck('name')->join(', ').'.');
            }
        }

        return Response::structured($this->present($recipe, $variant, (float) ($validated['scale'] ?? 1)));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Recipe $recipe, ?RecipeVariant $variant, float $scale): array
    {
        $shortfall = $recipe->shortfall($variant);

        return [
            'id' => $recipe->id,
            'name' => $recipe->name,
            'url' => RecipeResource::getUrl('view', ['record' => $recipe]),
            'source' => $recipe->source,
            'cuisine' => $recipe->cuisine,
            'course' => $recipe->course?->value,
            'protein' => $recipe->protein?->value,
            'diets' => $recipe->diets ?? [],
            'tags' => $recipe->tags ?? [],
            'servings' => $recipe->servings,
            'scale' => $scale,
            'made_as' => $variant?->name,
            'version' => $recipe->version,
            'readiness' => $shortfall->readiness()->value,
            'missing' => $shortfall->describe() ?: null,
            'ingredients' => $recipe->linesFor($variant)->map(fn (RecipeIngredient $line): array => [
                'name' => $line->name,
                'quantity' => $line->quantityFor($scale),
                'note' => $line->note,
                'optional' => $line->is_optional,
                'on_hand' => $line->availability()->value,
                'where' => $line->whereItIs(),
                'ready_on' => $line->readyOn()?->toDateString(),
                'substitute_on_hand' => $line->availability()->isMissing() ? $line->substituteOnHand()?->describe($scale) : null,
                'on_shopping_list' => $line->isOnShoppingList(),
            ])->values()->all(),
            'substitutes' => $recipe->substitutes->map(fn (RecipeSubstitute $substitute): array => [
                'instead_of' => $substitute->instead_of,
                'use' => $substitute->ingredients->map(fn (RecipeIngredient $line): array => [
                    'name' => $line->name,
                    'quantity' => $line->quantity,
                ])->values()->all(),
                'note' => $substitute->note,
                'summary' => $substitute->describe($scale),
                'on_hand' => $substitute->availability()->value,
            ])->values()->all(),
            'variants' => $recipe->variants->map(fn (RecipeVariant $variant): array => [
                'name' => $variant->name,
                'notes' => $variant->notes,
                'changes' => $variant->ingredients->map(fn (RecipeIngredient $line): array => [
                    'name' => $line->name,
                    'quantity' => $line->quantity,
                    'note' => $line->note,
                    'optional' => $line->is_optional,
                    'leave_out' => $line->is_left_out,
                ])->values()->all(),
            ])->values()->all(),
            'equipment' => $recipe->equipment->map(fn (RecipeEquipment $piece): array => [
                'name' => $piece->name,
                'in_kitchen' => $piece->isInKitchen(),
            ])->values()->all(),
            'steps' => $recipe->steps ?? [],
            'notes' => $recipe->notes,
            'cook_log' => $recipe->cookLogs()->limit(3)->get()->map(fn (CookLog $log): array => [
                'id' => $log->id,
                'cooked_on' => $log->cooked_on->toDateString(),
                'variant' => $log->variant,
                'thoughts' => $log->thoughts,
                'next_time' => $log->next_time,
            ])->all(),
            'waiting_notes' => $recipe->waitingNotes()->map(fn (CookLog $log): array => [
                'cook_log_id' => $log->id,
                'cooked_on' => $log->cooked_on->toDateString(),
                'made_as' => $log->madeAs(),
                'next_time' => $log->next_time,
            ])->all(),
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
            'recipe_id' => $schema->integer()
                ->description('The recipe\'s id. Give this or name.'),
            'name' => $schema->string()
                ->description('The recipe\'s name, or part of it.'),
            'variant' => $schema->string()
                ->description('Make it as this variant, by name.'),
            'scale' => $schema->number()
                ->min(0.1)
                ->max(10)
                ->default(1)
                ->description('How much to make: 0.5 halves it, 2 doubles it.'),
        ];
    }
}
