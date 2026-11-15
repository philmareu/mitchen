<?php

namespace App\Models;

use App\Enums\StockLevel;
use App\Enums\StorageLocation;
use App\Enums\Unit;
use App\Models\Builders\InventoryItemBuilder;
use App\Support\Quantity;
use Carbon\CarbonInterface;
use Database\Factories\InventoryItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Some of an ingredient that's in the kitchen right now, in one place.
 *
 * Its quantity lives in three columns (amount, unit, stock_level); read and
 * write it through the `quantity` attribute, which also accepts typed text
 * such as "half a jar" or "low".
 *
 * `ready_on` is for something in the kitchen that isn't ready to use yet (avocados bought
 * hard, peppers left to get very ripe): until that day it's ripening, and recipes plan
 * ahead for it instead of counting it as there. A past date means it's ready.
 */
#[Fillable(['ingredient_id', 'location', 'quantity', 'expires_on', 'ready_on', 'notes'])]
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
            'ready_on' => 'date',
        ];
    }

    /**
     * Not ready to use yet.
     */
    public function isRipening(): bool
    {
        return $this->ripeningUntil() !== null;
    }

    /**
     * When it'll be ready, while it's ripening; null once it's ready.
     */
    public function ripeningUntil(): ?CarbonInterface
    {
        return $this->ready_on !== null && $this->ready_on->isAfter(today()) ? $this->ready_on : null;
    }

    /**
     * Set when it's ready: a date after today means it's still ripening; today, an earlier
     * date or none means it's ready now.
     */
    public function ripenUntil(?CarbonInterface $readyOn): void
    {
        $this->ready_on = $readyOn !== null && $readyOn->isAfter(today()) ? Carbon::instance($readyOn)->startOfDay() : null;
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
