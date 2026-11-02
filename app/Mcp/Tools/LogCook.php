<?php

namespace App\Mcp\Tools;

use App\Models\CookLog;
use App\Models\Recipe;
use App\Models\RecipeVariant;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Arr;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Add to a recipe\'s cook log once the cook has made it ("Made it"): when (today unless they say), which variant, how it turned out (thoughts) and what to change next time (next_time). Next-time notes wait on the recipe until the cook works them into a new version. To add to an earlier entry, such as thoughts once the pickles have sat a few days, pass its cook_log_id from get-recipe; only the fields you pass change.')]
class LogCook extends Tool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'recipe_id' => ['required', 'integer'],
            'cook_log_id' => ['nullable', 'integer'],
            'cooked_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'variant' => ['nullable', 'string', 'max:255'],
            'thoughts' => ['nullable', 'string', 'max:5000'],
            'next_time' => ['nullable', 'string', 'max:5000'],
        ]);

        $recipe = Recipe::query()->with('variants')->find($validated['recipe_id']);

        if ($recipe === null) {
            return Response::error("Recipe {$validated['recipe_id']} was not found. what-to-cook lists them all.");
        }

        $entry = Arr::only($validated, ['cooked_on', 'variant', 'thoughts', 'next_time']);

        if (filled($entry['variant'] ?? null)) {
            $variant = $recipe->variants->first(fn (RecipeVariant $variant): bool => mb_strtolower($variant->name) === mb_strtolower(trim($entry['variant'])));

            if ($variant === null) {
                return Response::error($recipe->variants->isEmpty()
                    ? "{$recipe->name} has no variants."
                    : "{$recipe->name} has these variants: ".$recipe->variants->pluck('name')->join(', ').'.');
            }

            $entry['variant'] = $variant->name;
        }

        if (isset($validated['cook_log_id'])) {
            $log = $recipe->cookLogs()->find($validated['cook_log_id']);

            if ($log === null) {
                return Response::error("{$recipe->name} has no cook log entry {$validated['cook_log_id']}.");
            }

            $log->update($entry);
        } else {
            $log = $recipe->cookLogs()->create(['cooked_on' => today()->toDateString(), ...$entry]);
        }

        return Response::structured([
            'cook_log' => [
                'id' => $log->id,
                'recipe' => $recipe->name,
                'cooked_on' => $log->cooked_on->toDateString(),
                'version' => $log->version,
                'variant' => $log->variant,
                'thoughts' => $log->thoughts,
                'next_time' => $log->next_time,
            ],
            'waiting_notes' => $recipe->waitingNotes()->map(fn (CookLog $waiting): string => $waiting->next_time)->all(),
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
                ->description('The recipe, by id from what-to-cook or get-recipe.')
                ->required(),
            'cook_log_id' => $schema->integer()
                ->description('An earlier entry to add to, instead of making a new one.'),
            'cooked_on' => $schema->string()
                ->format('date')
                ->description('When it was made, if not today.'),
            'variant' => $schema->string()
                ->description('The variant it was made as, by name. Leave out for the recipe as written.'),
            'thoughts' => $schema->string()
                ->description('How it turned out, in the cook\'s words.'),
            'next_time' => $schema->string()
                ->description('What to change next time, in the cook\'s words: "more salt, less vinegar".'),
        ];
    }
}
