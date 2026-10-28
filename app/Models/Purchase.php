<?php

namespace App\Models;

use Database\Factories\PurchaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Number;

/**
 * One shop: where and when, what it came to, and what was bought. The total is the
 * receipt's when there is one, otherwise the sum of the prices that are known.
 */
#[Fillable(['purchased_on', 'total', 'notes'])]
class Purchase extends Model
{
    /** @use HasFactory<PurchaseFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'purchased_on' => 'date',
            'total' => 'float',
        ];
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * @return HasMany<PurchaseItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    /**
     * Fill in a missing total from the prices that are known.
     */
    public function addUpMissingTotal(): void
    {
        $priced = $this->items()->whereNotNull('price');

        if ($this->total === null && $priced->exists()) {
            $this->update(['total' => round((float) $priced->sum('price'), 2)]);
        }
    }

    /**
     * "Costco, $54.12", with the date when it wasn't today.
     */
    public function summary(): string
    {
        return collect([
            $this->store?->name,
            $this->purchased_on->isToday() ? null : $this->purchased_on->format('M j'),
            $this->total === null ? null : Number::currency($this->total),
        ])->filter()->join(', ');
    }
}
