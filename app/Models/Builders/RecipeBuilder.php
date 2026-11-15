<?php

namespace App\Models\Builders;

use App\Enums\Course;
use App\Enums\Diet;
use App\Enums\Protein;
use App\Enums\Readiness;
use App\Models\Recipe;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * @extends Builder<Recipe>
 */
class RecipeBuilder extends Builder
{
    public function named(string $name): static
    {
        return $this->whereRaw('lower(name) = ?', [mb_strtolower(trim($name))]);
    }

    /**
     * The cuisines the recipes use, A to Z.
     *
     * @return Collection<int, string>
     */
    public function cuisines(): Collection
    {
        return (clone $this)
            ->whereNotNull('cuisine')
            ->distinct()
            ->reorder('cuisine')
            ->pluck('cuisine')
            ->toBase();
    }

    /**
     * The tags the recipes use, A to Z.
     *
     * @return Collection<int, string>
     */
    public function tagsInUse(): Collection
    {
        return (clone $this)
            ->whereNotNull('tags')
            ->pluck('tags')
            ->flatten()
            ->map(fn (mixed $tag): string => (string) $tag)
            ->unique(fn (string $tag): string => mb_strtolower($tag))
            ->sort(fn (string $a, string $b): int => strcasecmp($a, $b))
            ->values()
            ->toBase();
    }

    public function whereCuisine(string $cuisine): static
    {
        return $this->whereRaw('lower(cuisine) = ?', [mb_strtolower(trim($cuisine))]);
    }

    public function whereCourse(Course ...$courses): static
    {
        return $this->whereIn('course', array_map(fn (Course $course): string => $course->value, $courses));
    }

    public function whereProtein(Protein ...$proteins): static
    {
        return $this->whereIn('protein', array_map(fn (Protein $protein): string => $protein->value, $proteins));
    }

    /**
     * Recipes that meet every one of these diets.
     */
    public function whereDiet(Diet ...$diets): static
    {
        foreach ($diets as $diet) {
            $this->whereJsonContains('diets', $diet->value);
        }

        return $this;
    }

    /**
     * Recipes with this tag, ignoring case (tags take the spelling already in use when saved).
     */
    public function whereTag(string $tag): static
    {
        $spelled = Recipe::query()->tagsInUse()->first(fn (string $existing): bool => mb_strtolower($existing) === mb_strtolower(trim($tag))) ?? trim($tag);

        return $this->whereJsonContains('tags', $spelled);
    }

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
