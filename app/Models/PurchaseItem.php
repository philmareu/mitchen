<?php

namespace App\Models;

use App\Enums\StockLevel;
use App\Enums\Unit;
use App\Support\Quantity;
use Database\Factories\PurchaseItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Number;

/**
 * One line of a purchase: how much of something was bought and what it cost. Linked to the
 * catalog ingredient of the same name whenever the name changes, so prices can be compared;
 * anything else stays free text. The quantity is stored and read like an inventory item's.
 */
#[Fillable(['name', 'as_written', 'quantity', 'price'])]
class PurchaseItem extends Model
{
    /** @use HasFactory<PurchaseItemFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (PurchaseItem $item): void {
            if ($item->exists && ! $item->isDirty('name')) {
                return;
            }

            $ingredient = Ingredient::query()->named($item->name)->first();

            $item->ingredient()->associate($ingredient);

            if ($ingredient !== null) {
                $item->name = $ingredient->name;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'unit' => Unit::class,
            'stock_level' => StockLevel::class,
            'price' => 'float',
        ];
    }

    /**
     * @return BelongsTo<Purchase, $this>
     */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    /**
     * @return BelongsTo<Ingredient, $this>
     */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    /**
     * What it cost, per unit when the amount is known ("$3.49/lb", "$0.50 each"), otherwise
     * the line's price. Null without a price.
     */
    public function formattedPrice(): ?string
    {
        if ($this->price === null) {
            return null;
        }

        $quantity = $this->quantity;

        if (! $quantity->isMeasured() || $quantity->amount === null || $quantity->amount <= 0) {
            return Number::currency($this->price);
        }

        $each = Number::currency($this->price / $quantity->amount);

        return $quantity->unit === null ? "{$each} each" : "{$each}/{$quantity->unit->labelFor(1)}";
    }

    /**
     * "Costco, Oct 28": where and when it was bought.
     */
    public function whereAndWhen(): string
    {
        return collect([$this->purchase->store?->name, $this->purchase->purchased_on->format('M j')])->filter()->join(', ');
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
