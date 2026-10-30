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
}
