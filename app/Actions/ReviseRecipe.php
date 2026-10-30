<?php

namespace App\Actions;

use App\Ai\Agents\RecipeReviser;
use App\Models\CookLog;
use App\Models\Equipment;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeSubstitute;
use App\Models\RecipeVariant;
use App\Support\RecipeDraft;

/**
 * Asks the assistant for the next version of a recipe, from the cook's notes after making it
 * and anything else they typed, and tidies its answer the way ReadRecipe does. Nothing is
 * saved: the cook checks the draft first (UpdateRecipe saves it).
 */
class ReviseRecipe
{
    public function __construct(private ReadRecipe $read) {}

    /**
     * @param  list<int>  $cookLogIds  Cook-log entries whose notes to work in.
     */
    public function handle(Recipe $recipe, array $cookLogIds, string $instructions = ''): RecipeDraft
    {
        $ingredients = Ingredient::query()->orderBy('name')->pluck('name');
        $equipment = Equipment::query()->orderBy('name')->pluck('name');

        $logs = $recipe->cookLogs()->whereKey($cookLogIds)->reorder('cooked_on')->get();

        $response = RecipeReviser::make()->prompt($this->prompt($recipe, $logs->all(), $instructions, $ingredients->join(', '), $equipment->join(', ')));

        return $this->read->tidy($response, $ingredients, $equipment);
    }

    /**
     * @param  list<CookLog>  $logs
     */
    private function prompt(Recipe $recipe, array $logs, string $instructions, string $catalog, string $kitchen): string
    {
        $current = json_encode([
            'name' => $recipe->name,
            'source' => $recipe->source,
            'servings' => $recipe->servings,
            'ingredients' => $recipe->ingredients->map(fn (RecipeIngredient $line): array => [
                'quantity' => $line->quantity,
                'name' => $line->name,
                'note' => $line->note,
                'optional' => $line->is_optional,
                'category' => $line->ingredient?->category?->value,
            ])->all(),
            'substitutes' => $recipe->substitutes->map(fn (RecipeSubstitute $substitute): array => [
                'instead_of' => $substitute->instead_of,
                'use' => $substitute->ingredients->map(fn (RecipeIngredient $line): array => [
                    'quantity' => $line->quantity,
                    'name' => $line->name,
                    'category' => $line->ingredient?->category?->value,
                ])->all(),
                'note' => $substitute->note,
            ])->all(),
            'variants' => $recipe->variants->map(fn (RecipeVariant $variant): array => [
                'name' => $variant->name,
                'notes' => $variant->notes,
                'changes' => $variant->ingredients->map(fn (RecipeIngredient $line): array => [
                    'quantity' => $line->quantity,
                    'name' => $line->name,
                    'leave_out' => $line->is_left_out,
                    'category' => $line->ingredient?->category?->value,
                ])->all(),
            ])->all(),
            'equipment' => $recipe->equipment->pluck('name')->all(),
            'steps' => array_values($recipe->steps ?? []),
            'notes' => $recipe->notes,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $notes = collect($logs)
            ->map(fn (CookLog $log): string => sprintf(
                '- Made %s (%s).%s Next time: %s',
                $log->cooked_on->format('M j, Y'),
                lcfirst($log->madeAs()),
                filled($log->thoughts) ? ' How it turned out: '.$log->thoughts : '',
                $log->next_time ?? '(nothing)',
            ))
            ->join("\n") ?: '(none)';

        $instructions = trim($instructions) === '' ? '(none)' : $instructions;
        $catalog = $catalog ?: '(empty)';
        $kitchen = $kitchen ?: '(none listed)';

        return <<<TEXT
        The current recipe (version {$recipe->version}):
        {$current}

        The cook's notes from making it:
        {$notes}

        More instructions from the cook:
        """
        {$instructions}
        """

        Ingredients in the catalog: {$catalog}

        Equipment the kitchen has: {$kitchen}
        TEXT;
    }
}
