<?php

namespace App\Models;

use App\Enums\Availability;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Something to use instead of one of a recipe's ingredients when it's out: instead of
 * buttermilk, milk and lemon juice ("let it sit 5 minutes"). Its lines are RecipeIngredients,
 * so they link to the catalog and scale like the recipe's own. An ingredient can have several.
 *
 * Whenever `instead_of` changes it's linked to the catalog ingredient of that name, which is
 * how it finds the recipe line it stands in for.
 */
#[Fillable(['instead_of', 'note', 'sort'])]
class RecipeSubstitute extends Model
{
    protected static function booted(): void
    {
        static::saving(function (RecipeSubstitute $substitute): void {
            if ($substitute->ingredient_id !== null && ! $substitute->isDirty('instead_of')) {
                return;
            }

            $ingredient = Ingredient::query()->named($substitute->instead_of)->first();

            $substitute->ingredient()->associate($ingredient);
            $substitute->instead_of = $ingredient->name ?? trim($substitute->instead_of);
        });
    }

    /**
     * @return BelongsTo<Recipe, $this>
     */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    /**
     * @return BelongsTo<Ingredient, $this>
     */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    /**
     * @return HasMany<RecipeIngredient, $this>
     */
    public function ingredients(): HasMany
    {
        return $this->hasMany(RecipeIngredient::class, 'substitute_id')->orderBy('sort')->orderBy('id');
    }

    /**
     * What's on hand for it: out when any line it needs is out, running low when any is low.
     */
    public function availability(): Availability
    {
        $lines = $this->ingredients->reject(fn (RecipeIngredient $line): bool => $line->is_optional);
        $availability = $lines->map(fn (RecipeIngredient $line): Availability => $line->availability());

        return match (true) {
            $lines->isEmpty(), $availability->contains(Availability::Out) => Availability::Out,
            $availability->contains(Availability::Low) => Availability::Low,
            default => Availability::OnHand,
        };
    }

    /**
     * "1 cup Milk + 1 tbsp Lemon juice", scaled with the recipe.
     */
    public function describe(float $scale = 1): string
    {
        return $this->ingredients
            ->map(fn (RecipeIngredient $line): string => trim($line->quantityFor($scale).' '.$line->name))
            ->join(' + ');
    }
}
