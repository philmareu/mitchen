<?php

namespace App\Actions;

use App\Enums\Diet;
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

            $recipe->update($draft->attributes());

            $recipe->substitutes()->delete();
            $recipe->variants()->delete();
            $recipe->ingredients()->delete();
            $recipe->equipment()->delete();
            $this->add->addLines($recipe, $draft);

            return $this->record->handle($recipe, $before, $note, $cookLogIds);
        });
    }

    /**
     * What saving the draft would change, without saving it: the name, source, notes and how
     * it's described (cuisine, course, main protein, diets, tags), then
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
            'Cuisine' => [$recipe->cuisine, $draft->cuisine],
            'Course' => [$recipe->course?->getLabel(), $draft->course?->getLabel()],
            'Main protein' => [$recipe->protein?->getLabel(), $draft->protein?->getLabel()],
            'Diets' => [self::listed(array_map(fn (Diet $diet): string => $diet->getLabel(), $recipe->dietList())), self::listed(array_map(fn (Diet $diet): string => $diet->getLabel(), $draft->diets))],
            'Tags' => [self::listed($recipe->tags ?? []), self::listed($draft->tags)],
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

    /**
     * @param  list<string>  $values
     */
    private static function listed(array $values): ?string
    {
        return $values === [] ? null : implode(', ', $values);
    }
}
