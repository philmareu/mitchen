<?php

namespace App\Actions;

use App\Models\CookLog;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Support\RecipeChanges;

/**
 * After a recipe is saved: when what's versioned (Recipe::snapshot()) changed, the
 * change becomes a new version that keeps the old one, and the cook-log notes it took in are
 * marked as used. Nothing happens when only the name, notes or photos changed.
 */
class RecordRecipeVersion
{
    /**
     * @param  array<string, mixed>  $before  Recipe::snapshot() from before the save.
     * @param  list<int>  $cookLogIds  Cook-log entries whose next-time notes this version took in.
     */
    public function handle(Recipe $recipe, array $before, ?string $note = null, array $cookLogIds = []): ?RecipeVersion
    {
        $changes = RecipeChanges::between($before, $recipe->snapshot());

        if ($changes === []) {
            return null;
        }

        $number = $recipe->version + 1;

        $version = $recipe->versions()->create([
            'number' => $number,
            'previous' => $before,
            'changes' => $changes,
            'note' => filled($note) ? trim($note) : null,
        ]);

        $recipe->forceFill(['version' => $number])->save();

        CookLog::query()
            ->whereBelongsTo($recipe)
            ->whereKey($cookLogIds)
            ->whereNull('used_in_version')
            ->update(['used_in_version' => $number]);

        return $version;
    }
}
