<?php

namespace App\Actions;

use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Support\RecipeDraft;
use Illuminate\Support\Facades\DB;

/**
 * Saves a revised recipe the cook checked as its next version, all or nothing: the lines are
 * replaced with the draft's, then RecordRecipeVersion keeps the old version and marks the
 * cook-log notes it took in. The photos stay.
 */
class UpdateRecipe
{
    public function __construct(
        private AddRecipe $add,
        private RecordRecipeVersion $record,
    ) {}

    /**
     * @param  list<int>  $cookLogIds  Cook-log entries whose notes the draft took in.
     * @return RecipeVersion|null The new version; null when the lines, steps and servings didn't change.
     */
    public function handle(Recipe $recipe, RecipeDraft $draft, ?string $note = null, array $cookLogIds = []): ?RecipeVersion
    {
        return DB::transaction(function () use ($recipe, $draft, $note, $cookLogIds): ?RecipeVersion {
            $before = $recipe->snapshot();

            $recipe->update([
                'name' => $draft->name,
                'source' => $draft->source,
                'servings' => $draft->servings,
                'steps' => $draft->steps,
                'notes' => $draft->notes,
            ]);

            $recipe->substitutes()->delete();
            $recipe->variants()->delete();
            $recipe->ingredients()->delete();
            $recipe->equipment()->delete();
            $this->add->addLines($recipe, $draft);

            return $this->record->handle($recipe, $before, $note, $cookLogIds);
        });
    }

    /**
     * What saving the draft would change, without saving it: the name, source and notes, then
     * the versioned changes (RecipeChanges). It saves inside a transaction and rolls it back,
     * so the list is exactly what handle() would record.
     *
     * @return list<string>
     */
    public function preview(Recipe $recipe, RecipeDraft $draft): array
    {
        $unversioned = collect([
            'Name' => [$recipe->name, $draft->name],
            'Source' => [$recipe->source, $draft->source],
            'Notes' => [$recipe->notes, $draft->notes],
        ])
            ->filter(fn (array $values): bool => $values[0] !== $values[1])
            ->map(fn (array $values, string $field): string => $field === 'Notes'
                ? 'Notes: '.($values[1] === null ? 'removed' : 'changed')
                : "{$field}: ".($values[0] ?? 'none').' → '.($values[1] ?? 'none'))
            ->values();

        DB::beginTransaction();

        try {
            $versioned = $this->handle($recipe, $draft)->changes ?? [];
        } finally {
            DB::rollBack();
            $recipe->refresh();
        }

        return [...$unversioned->all(), ...$versioned];
    }
}
