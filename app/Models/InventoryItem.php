<?php

namespace App\Models;

use App\Enums\StockLevel;
use App\Enums\StorageLocation;
use App\Enums\Unit;
use App\Models\Builders\InventoryItemBuilder;
use App\Support\Quantity;
use Database\Factories\InventoryItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Some of an ingredient that's in the kitchen right now, in one place.
 *
 * Its quantity lives in three columns (amount, unit, stock_level); read and
 * write it through the `quantity` attribute, which also accepts typed text
 * such as "half a jar" or "low".
 */
#[Fillable(['ingredient_id', 'location', 'quantity', 'expires_on', 'notes'])]
#[UseEloquentBuilder(InventoryItemBuilder::class)]
class InventoryItem extends Model
{
    /** @use HasFactory<InventoryItemFactory> */
    use HasFactory;

    protected $attributes = [
        'location' => 'pantry',
    ];

    protected function casts(): array
    {
        return [
            'location' => StorageLocation::class,
            'amount' => 'float',
            'unit' => Unit::class,
            'stock_level' => StockLevel::class,
            'expires_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Ingredient, $this>
     */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    /**
     * @return Attribute<Quantity, Quantity|StockLevel|string|null>
     */
    protected function quantity(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value, array $attributes): Quantity => Quantity::fromStorage(
                $attributes['amount'] ?? null,
                isset($attributes['unit']) ? Unit::from($attributes['unit']) : null,
                isset($attributes['stock_level']) ? StockLevel::from($attributes['stock_level']) : null,
            ),
            set: fn (Quantity|StockLevel|string|null $value): array => Quantity::from($value)->toStorage(),
        );
    }
}
