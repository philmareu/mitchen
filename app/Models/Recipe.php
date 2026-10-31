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

    /**
     * What to eager load to say what's on hand for it.
     *
     * @var list<string>
     */
    public const array KITCHEN = [
        'ingredients.ingredient.inventoryItems',
        'substitutes.ingredients.ingredient.inventoryItems',
        'variants.ingredients.ingredient.inventoryItems',
        'equipment',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'version' => 1,
    ];

    protected function casts(): array
    {
        return [
            'servings' => 'integer',
            'steps' => 'array',
            'photos' => 'array',
            'version' => 'integer',
        ];
    }

    /**
     * Its own ingredient lines (not those of its substitutes or variants).
     *
     * @return HasMany<RecipeIngredient, $this>
     */
    public function ingredients(): HasMany
    {
        return $this->hasMany(RecipeIngredient::class)
            ->whereNull('substitute_id')
            ->whereNull('variant_id')
            ->orderBy('sort')
            ->orderBy('id')
            ->chaperone();
    }

    /**
     * @return HasMany<RecipeSubstitute, $this>
     */
    public function substitutes(): HasMany
    {
        return $this->hasMany(RecipeSubstitute::class)->orderBy('sort')->orderBy('id');
    }

    /**
     * @return HasMany<RecipeEquipment, $this>
     */
    public function equipment(): HasMany
    {
        return $this->hasMany(RecipeEquipment::class)->orderBy('sort')->orderBy('id');
    }

    /**
     * @return HasMany<RecipeVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(RecipeVariant::class)->orderBy('sort')->orderBy('id');
    }

    /**
     * The ingredient lines for making it as a variant: the variant's line for an ingredient
     * replaces the recipe's (and drops it when it's left out), its other lines are added at the
     * end. The recipe's own lines without one.
     *
     * @return Collection<int, RecipeIngredient>
     */
    public function linesFor(?RecipeVariant $variant): Collection
    {
        if ($variant === null) {
            return $this->ingredients;
        }

        $changes = $variant->ingredients->each(fn (RecipeIngredient $line) => $line->setRelation('recipe', $this));
        $ownIngredients = $this->ingredients->pluck('ingredient_id');

        return $this->ingredients
            ->map(fn (RecipeIngredient $line): RecipeIngredient => $changes->firstWhere('ingredient_id', $line->ingredient_id) ?? $line)
            ->concat($changes->reject(fn (RecipeIngredient $line): bool => $ownIngredients->contains($line->ingredient_id)))
            ->reject(fn (RecipeIngredient $line): bool => $line->is_left_out)
            ->values();
    }

    /**
     * Earlier versions, newest first.
     *
     * @return HasMany<RecipeVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(RecipeVersion::class)->orderByDesc('number');
    }

    /**
     * Each time the cook made it, latest first.
     *
     * @return HasMany<CookLog, $this>
     */
    public function cookLogs(): HasMany
    {
        return $this->hasMany(CookLog::class)->orderByDesc('cooked_on')->orderByDesc('id');
    }

    /**
     * Notes for next time that no version has taken in yet, oldest first.
     *
     * @return Collection<int, CookLog>
     */
    public function waitingNotes(): Collection
    {
        return $this->cookLogs()
            ->whereNotNull('next_time')
            ->whereNull('used_in_version')
            ->reorder('cooked_on')
            ->orderBy('id')
            ->get();
    }

    /**
     * What a version is made of, to keep when it changes and to compare: servings, ingredient
     * lines, substitutes, variants, equipment and steps. The name, source, notes and photos aren't versioned.
     *
     * @return array{servings: int|null, ingredients: list<array{quantity: string|null, name: string, note: string|null, is_optional: bool}>, substitutes: list<array{instead_of: string, note: string|null, ingredients: list<array{quantity: string|null, name: string}>}>, variants: list<array{name: string, notes: string|null, ingredients: list<array{quantity: string|null, name: string, is_left_out: bool}>}>, equipment: list<string>, steps: list<string>}
     */
    public function snapshot(): array
    {
        $this->load(['ingredients', 'substitutes.ingredients', 'variants.ingredients', 'equipment']);

        return [
            'servings' => $this->servings,
            'ingredients' => $this->ingredients
                ->map(fn (RecipeIngredient $line): array => [
                    'quantity' => $line->quantity,
                    'name' => $line->name,
                    'note' => $line->note,
                    'is_optional' => $line->is_optional,
                ])
                ->values()
                ->all(),
            'substitutes' => $this->substitutes
                ->map(fn (RecipeSubstitute $substitute): array => [
                    'instead_of' => $substitute->instead_of,
                    'note' => $substitute->note,
                    'ingredients' => $substitute->ingredients
                        ->map(fn (RecipeIngredient $line): array => ['quantity' => $line->quantity, 'name' => $line->name])
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all(),
            'variants' => $this->variants
                ->map(fn (RecipeVariant $variant): array => [
                    'name' => $variant->name,
                    'notes' => $variant->notes,
                    'ingredients' => $variant->ingredients
                        ->map(fn (RecipeIngredient $line): array => ['quantity' => $line->quantity, 'name' => $line->name, 'is_left_out' => $line->is_left_out])
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all(),
            'equipment' => $this->equipment->pluck('name')->values()->all(),
            'steps' => array_values($this->steps ?? []),
        ];
    }

    /**
     * What it needs that the kitchen doesn't have: ingredients that are out with no substitute
     * on hand (optional ones aside) and equipment that isn't listed.
     *
     * @return list<string>
     */
    public function missing(): array
    {
        $ingredients = $this->ingredients
            ->filter(fn (RecipeIngredient $line): bool => ! $line->is_optional && $line->availability() === Availability::Out && ! $line->isCoveredBySubstitute())
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
    public function ingredientsToBuy(?RecipeVariant $variant = null): Collection
    {
        return $this->linesFor($variant)
            ->reject(fn (RecipeIngredient $line): bool => $line->availability() === Availability::OnHand || $line->isOnShoppingList())
            ->values();
    }
}
