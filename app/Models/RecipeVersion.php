<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A change that made a new version of a recipe: when, why (`note`), what changed, and the
 * recipe as it was before (`previous`, a Recipe::snapshot() of the version before this one).
 * Version 1, the recipe as first added, has no row.
 *
 * Snapshots from before recipes had substitutes and variants lack those keys.
 *
 * @property array{servings: int|null, ingredients: list<array{quantity: string|null, name: string, note: string|null, is_optional: bool}>, substitutes?: list<array{instead_of: string, note: string|null, ingredients: list<array{quantity: string|null, name: string}>}>, variants?: list<array{name: string, notes: string|null, ingredients: list<array{quantity: string|null, name: string, is_left_out: bool}>}>, equipment: list<string>, steps: list<string>} $previous
 * @property list<string> $changes
 */
#[Fillable(['number', 'previous', 'changes', 'note'])]
class RecipeVersion extends Model
{
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'previous' => 'array',
            'changes' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Recipe, $this>
     */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }
}
