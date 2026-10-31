<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named way of making a recipe, picked before cooking: hot salsa, blueberry pancakes. Its
 * lines are changes to the recipe's: one for an ingredient the recipe has replaces that line
 * (a new amount, or `is_left_out`), any other is added. See Recipe::linesFor().
 */
#[Fillable(['name', 'notes', 'sort'])]
class RecipeVariant extends Model
{
    /**
     * @return BelongsTo<Recipe, $this>
     */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    /**
     * @return HasMany<RecipeIngredient, $this>
     */
    public function ingredients(): HasMany
    {
        return $this->hasMany(RecipeIngredient::class, 'variant_id')->orderBy('sort')->orderBy('id');
    }
}
