<?php

namespace App\Models;

use App\Enums\Availability;
use App\Enums\StockLevel;
use App\Support\Quantity;
use App\Support\UnreadableQuantity;
use Database\Factories\RecipeIngredientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One ingredient line of a recipe: a free-text quantity as the recipe says it ("2 cups",
 * "a pinch", "to taste"), the ingredient, and a note ("diced", "or butter").
 *
 * Whenever its name changes (or it lost its link) it's linked to the catalog ingredient of
 * that name, taking its spelling. A name that isn't in the catalog is added to it as a
 * one-off, so buying it later, or putting it on the shopping list, finds the same ingredient.
 *
 * The lines of a substitute (`substitute_id`) and a variant's changes (`variant_id`, where
 * `is_left_out` means the variant goes without it) are RecipeIngredients too, in the same recipe.
 */
#[Fillable(['name', 'quantity', 'note', 'is_optional', 'is_left_out', 'sort'])]
class RecipeIngredient extends Model
{
    /** @use HasFactory<RecipeIngredientFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (RecipeIngredient $line): void {
            if ($line->isDirty('substitute_id') && $line->substitute !== null) {
                $line->recipe()->associate($line->substitute->recipe_id);
            }

            if ($line->isDirty('variant_id') && $line->variant !== null) {
                $line->recipe()->associate($line->variant->recipe_id);
            }

            if ($line->ingredient_id !== null && ! $line->isDirty('name')) {
                return;
            }

            $ingredient = Ingredient::query()->named($line->name)->first()
                ?? Ingredient::create(['name' => Str::ucfirst(trim($line->name)), 'is_core' => false]);

            $line->ingredient()->associate($ingredient);
            $line->name = $ingredient->name;
        });
    }

    protected function casts(): array
    {
        return [
            'is_optional' => 'boolean',
            'is_left_out' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Recipe, $this>
     */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    /**
     * The substitute this is a line of; null for the recipe's own lines.
     *
     * @return BelongsTo<RecipeSubstitute, $this>
     */
    public function substitute(): BelongsTo
    {
        return $this->belongsTo(RecipeSubstitute::class);
    }

    /**
     * The variant this line is a change of; null for the recipe's own lines.
     *
     * @return BelongsTo<RecipeVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(RecipeVariant::class);
    }

    /**
     * @return BelongsTo<Ingredient, $this>
     */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    /**
     * On hand when it's always on hand (tap water) or any of it isn't low or out (an unmeasured
     * item counts), running low when all that's left is low, otherwise out.
     */
    public function availability(): Availability
    {
        if ($this->ingredient?->always_on_hand) {
            return Availability::OnHand;
        }

        $quantities = $this->ingredient?->inventoryItems->map(fn (InventoryItem $item): Quantity => $item->quantity);

        return match (true) {
            $quantities === null => Availability::Out,
            $quantities->contains(fn (Quantity $quantity): bool => ! $quantity->needsRestock()) => Availability::OnHand,
            $quantities->contains(fn (Quantity $quantity): bool => $quantity->level === StockLevel::Low) => Availability::Low,
            default => Availability::Out,
        };
    }

    /**
     * What the recipe says to use instead of it.
     *
     * @return Collection<int, RecipeSubstitute>
     */
    public function substitutes(): Collection
    {
        if ($this->ingredient_id === null || $this->substitute_id !== null) {
            return new Collection;
        }

        return $this->recipe->substitutes->where('ingredient_id', $this->ingredient_id)->values();
    }

    /**
     * A substitute the kitchen has (one that's all on hand before one that's running low).
     */
    public function substituteOnHand(): ?RecipeSubstitute
    {
        return $this->substitutes()->first(fn (RecipeSubstitute $substitute): bool => $substitute->availability() === Availability::OnHand)
            ?? $this->substitutes()->first(fn (RecipeSubstitute $substitute): bool => $substitute->availability() === Availability::Low);
    }

    /**
     * It's out, but a substitute isn't: the recipe can still be made.
     */
    public function isCoveredBySubstitute(): bool
    {
        return $this->availability() === Availability::Out && $this->substituteOnHand() !== null;
    }

    /**
     * What's left and where, such as "2 lb · Fridge, Low · Freezer". Null when it's out.
     */
    public function whereItIs(): ?string
    {
        return $this->ingredient?->inventoryItems
            ->reject(fn (InventoryItem $item): bool => $item->quantity->level === StockLevel::Out)
            ->map(fn (InventoryItem $item): string => ($item->quantity->format() ?: 'Some').' · '.$item->location->getLabel())
            ->join(', ') ?: null;
    }

    /**
     * Whether its ingredient is on the shopping list, ticked or not.
     */
    public function isOnShoppingList(): bool
    {
        return $this->ingredient?->isOnShoppingList() ?? false;
    }

    /**
     * The quantity as it reads when the recipe is scaled: amounts Mitchen can read are scaled
     * ("1½ cups" at ½ is "¾ cup"), anything else ("a pinch", "to taste") stays as written.
     */
    public function quantityFor(float $scale = 1): ?string
    {
        return $scale == 1 ? $this->quantity : ($this->measuredQuantity($scale)?->format() ?? $this->quantity);
    }

    /**
     * The quantity, scaled, when it's an amount Mitchen can read; null for "a pinch" or "to taste".
     */
    public function measuredQuantity(float $scale = 1): ?Quantity
    {
        if (blank($this->quantity) || $scale <= 0) {
            return null;
        }

        try {
            $quantity = Quantity::parse($this->quantity);
        } catch (UnreadableQuantity) {
            return null;
        }

        return $quantity->isMeasured() ? $quantity->times($scale) : null;
    }
}
