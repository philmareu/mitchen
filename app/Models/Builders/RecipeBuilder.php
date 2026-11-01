<?php

namespace App\Models\Builders;

use App\Enums\Readiness;
use App\Models\Recipe;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * @extends Builder<Recipe>
 */
class RecipeBuilder extends Builder
{
    /**
     * Recipes the kitchen has everything for, made as written or as a variant.
     */
    public function readyToCook(): static
    {
        return $this->whereReadiness(Readiness::Ready);
    }

    /**
     * Recipes as close as any of these, made the closest way (Recipe::closestShortfall()).
     * Substitutes, variants and seasons make this too fiddly for portable SQL, so it's worked
     * out in PHP and returned as a whereKey() scope, like IngredientBuilder::needsRestock().
     */
    public function whereReadiness(Readiness ...$readiness): static
    {
        return $this->whereKey(
            Recipe::query()
                ->readinessByKey()
                ->filter(fn (Readiness $closest): bool => in_array($closest, $readiness, true))
                ->keys()
                ->all(),
        );
    }

    /**
     * How close each recipe the query finds is, made the closest way, by id.
     *
     * @return Collection<int, Readiness>
     */
    public function readinessByKey(): Collection
    {
        return $this->with(Recipe::KITCHEN)
            ->get()
            ->mapWithKeys(fn (Recipe $recipe): array => [$recipe->id => $recipe->closestShortfall()->readiness()])
            ->toBase();
    }
}
