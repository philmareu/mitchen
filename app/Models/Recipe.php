<?php

namespace App\Models;

use App\Enums\Availability;
use App\Models\Builders\RecipeBuilder;
use App\Models\Concerns\OwnsPhotos;
use Database\Factories\RecipeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A recipe the cook keeps: their own, one they've tweaked, or one off a package. Its
 * ingredients point at the catalog and its equipment at the kitchen's equipment, so it can
 * say what's on hand. The photos are the original card or page, kept privately.
 */
#[Fillable(['name', 'source', 'servings', 'steps', 'notes', 'photos'])]
#[UseEloquentBuilder(RecipeBuilder::class)]
class Recipe extends Model
{
    /** @use HasFactory<RecipeFactory> */
    use HasFactory;

    use OwnsPhotos;

    /**
     * Where photos are stored on the disk.
     */
    public const string PHOTO_DIRECTORY = 'recipes';

    protected function casts(): array
    {
        return [
            'servings' => 'integer',
            'steps' => 'array',
            'photos' => 'array',
        ];
    }

    /**
     * @return HasMany<RecipeIngredient, $this>
     */
    public function ingredients(): HasMany
    {
        return $this->hasMany(RecipeIngredient::class)->orderBy('sort')->orderBy('id');
    }

    /**
     * @return HasMany<RecipeEquipment, $this>
     */
    public function equipment(): HasMany
    {
        return $this->hasMany(RecipeEquipment::class)->orderBy('sort')->orderBy('id');
    }

    /**
     * What it needs that the kitchen doesn't have: ingredients that are out (optional ones
     * aside) and equipment that isn't listed. Matches RecipeBuilder::readyToCook().
     *
     * @return list<string>
     */
    public function missing(): array
    {
        $ingredients = $this->ingredients
            ->filter(fn (RecipeIngredient $line): bool => ! $line->is_optional && $line->availability() === Availability::Out)
            ->map(fn (RecipeIngredient $line): string => $line->name);

        $equipment = $this->equipment
            ->reject(fn (RecipeEquipment $piece): bool => $piece->isInKitchen())
            ->map(fn (RecipeEquipment $piece): string => $piece->name);

        return $ingredients->toBase()->concat($equipment)->values()->all();
    }

    /**
     * Ingredients worth getting: out or running low, and not on the shopping list already.
     *
     * @return Collection<int, RecipeIngredient>
     */
    public function ingredientsToBuy(): Collection
    {
        return $this->ingredients
            ->reject(fn (RecipeIngredient $line): bool => $line->availability() === Availability::OnHand || $line->isOnShoppingList())
            ->values();
    }
}
