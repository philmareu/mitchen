<?php

namespace App\Support;

use App\Enums\Readiness;
use App\Enums\Sourcing;
use App\Models\RecipeIngredient;
use App\Models\RecipeVariant;
use Illuminate\Database\Eloquent\Collection;

/**
 * What a recipe needs that the kitchen hasn't got, made one way (as written or as a variant):
 * ingredients that are out with no substitute on hand (optional ones aside) and equipment the
 * kitchen doesn't have. It says how close the recipe is (Readiness).
 */
final readonly class RecipeShortfall
{
    /**
     * Up to this many ingredients from any store is a quick shop.
     */
    public const int QUICK_SHOP = 3;

    /**
     * @param  Collection<int, RecipeIngredient>  $ingredients
     * @param  list<string>  $equipment
     */
    public function __construct(
        public Collection $ingredients,
        public array $equipment = [],
        public ?RecipeVariant $variant = null,
    ) {}

    public function readiness(): Readiness
    {
        return match (true) {
            $this->equipment !== [] => Readiness::NeedsEquipment,
            $this->ingredients->isEmpty() => Readiness::Ready,
            $this->needingLeadTime()->isNotEmpty() => Readiness::PlanAhead,
            $this->ingredients->count() <= self::QUICK_SHOP => Readiness::QuickShop,
            default => Readiness::BigShop,
        };
    }

    /**
     * Missing ingredients that take planning to get: hard to find, out of season or made ahead.
     *
     * @return Collection<int, RecipeIngredient>
     */
    public function needingLeadTime(): Collection
    {
        return $this->ingredients
            ->filter(fn (RecipeIngredient $line): bool => (bool) $line->ingredient?->needsLeadTime())
            ->values();
    }

    /**
     * The longest it takes to get or make what's missing, in days, when the cook has said.
     */
    public function leadDays(): ?int
    {
        $days = $this->needingLeadTime()->max(fn (RecipeIngredient $line): ?int => $line->ingredient?->lead_days);

        return $days === null ? null : (int) $days;
    }

    /**
     * Missing ingredients to buy (not the ones the cook makes) that aren't on the shopping list yet.
     *
     * @return Collection<int, RecipeIngredient>
     */
    public function toBuy(): Collection
    {
        return $this->ingredients
            ->reject(fn (RecipeIngredient $line): bool => $line->ingredient?->sourcing === Sourcing::MakeAhead || $line->isOnShoppingList())
            ->values();
    }

    /**
     * Everything missing: the ingredients, then the equipment.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return $this->ingredients->pluck('name')->concat($this->equipment)->values()->all();
    }

    /**
     * What's missing, in a line: equipment first, since it rules the recipe out, then the
     * ingredients with what getting them takes ("Pickled red onions (make ahead, about 3 days),
     * Limes"). Empty when nothing is.
     */
    public function describe(): string
    {
        return collect($this->equipment)
            ->concat($this->ingredients->map(fn (RecipeIngredient $line): string => $line->ingredient?->needsLeadTime()
                ? $line->name.' ('.$line->ingredient->leadTime().')'
                : $line->name))
            ->join(', ');
    }

    /**
     * describe(), starting with the variant when it isn't the recipe as written. Null when
     * there's nothing to say.
     */
    public function summary(): ?string
    {
        $missing = $this->describe();

        return match (true) {
            $this->variant === null => $missing ?: null,
            $missing === '' => 'As '.$this->variant->name,
            default => 'As '.$this->variant->name.': '.$missing,
        };
    }
}
