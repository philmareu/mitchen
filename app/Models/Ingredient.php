<?php

namespace App\Models;

use App\Enums\IngredientCategory;
use App\Enums\StockLevel;
use App\Enums\Unit;
use App\Models\Builders\IngredientBuilder;
use App\Support\Quantity;
use Database\Factories\IngredientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Something you cook with. Core ingredients are staples you always keep on hand;
 * the rest are one-offs bought for a particular recipe.
 *
 * A core ingredient goes on the restock list when what's on hand gets down to its
 * restock point ("¼ jar", "2 cans", "out"), or by default when it's low or out.
 *
 * Some are always on hand and never tracked, like tap water: recipes count them as there.
 * Water and ice start out that way.
 */
#[Fillable(['name', 'category', 'is_core', 'always_on_hand', 'restock_point', 'notes'])]
#[UseEloquentBuilder(IngredientBuilder::class)]
class Ingredient extends Model
{
    /** @use HasFactory<IngredientFactory> */
    use HasFactory;

    /**
     * Names that start out always on hand.
     */
    private const array ALWAYS_ON_HAND = ['water', 'tap water', 'ice', 'ice water'];

    protected static function booted(): void
    {
        static::creating(function (Ingredient $ingredient): void {
            $ingredient->always_on_hand ??= in_array(mb_strtolower(trim($ingredient->name)), self::ALWAYS_ON_HAND, true);
        });
    }

    protected function casts(): array
    {
        return [
            'category' => IngredientCategory::class,
            'is_core' => 'boolean',
            'always_on_hand' => 'boolean',
            'restock_amount' => 'float',
            'restock_unit' => Unit::class,
            'restock_level' => StockLevel::class,
        ];
    }

    /**
     * @return HasMany<InventoryItem, $this>
     */
    public function inventoryItems(): HasMany
    {
        return $this->hasMany(InventoryItem::class);
    }

    /**
     * @return HasMany<ShoppingListItem, $this>
     */
    public function shoppingListItems(): HasMany
    {
        return $this->hasMany(ShoppingListItem::class);
    }

    /**
     * @return HasMany<PurchaseItem, $this>
     */
    public function purchaseItems(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    /**
     * The most recently recorded purchase of it that has a price.
     *
     * @return HasOne<PurchaseItem, $this>
     */
    public function lastPaid(): HasOne
    {
        return $this->hasOne(PurchaseItem::class)->ofMany(['id' => 'max'], fn (Builder $query) => $query->whereNotNull('price'));
    }

    public function needsRestocking(): bool
    {
        return $this->is_core && $this->restock_point->isReachedBy(
            $this->inventoryItems->map(fn (InventoryItem $item): Quantity => $item->quantity),
        );
    }

    /**
     * Whether it's on the shopping list, ticked or not: a ticked entry stays until it's put away.
     * Uses the loaded relation when there is one.
     */
    public function isOnShoppingList(): bool
    {
        return $this->relationLoaded('shoppingListItems')
            ? $this->shoppingListItems->isNotEmpty()
            : $this->shoppingListItems()->exists();
    }

    /**
     * When to restock, stored like an inventory quantity. Unknown means "when it's low or out".
     *
     * @return Attribute<Quantity, Quantity|StockLevel|string|null>
     */
    protected function restockPoint(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value, array $attributes): Quantity => Quantity::fromStorage(
                $attributes['restock_amount'] ?? null,
                isset($attributes['restock_unit']) ? Unit::from($attributes['restock_unit']) : null,
                isset($attributes['restock_level']) ? StockLevel::from($attributes['restock_level']) : null,
            ),
            set: function (Quantity|StockLevel|string|null $value): array {
                $storage = Quantity::from($value)->toStorage();

                return [
                    'restock_amount' => $storage['amount'],
                    'restock_unit' => $storage['unit'],
                    'restock_level' => $storage['stock_level'],
                ];
            },
        );
    }
}
