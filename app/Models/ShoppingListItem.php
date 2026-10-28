<?php

namespace App\Models;

use App\Enums\IngredientCategory;
use App\Models\Builders\ShoppingListItemBuilder;
use Database\Factories\ShoppingListItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something to get on the next shop. Whenever its name changes it's linked to the catalog
 * ingredient of that name, taking its spelling and aisle (category), or stays free text
 * for anything else ("Paper towels").
 *
 * Ticking it off (bought_at) puts it in the cart. It leaves the list once it's in the inventory:
 * put away from the list, restocked from the dashboard, or bought in a quick update.
 */
#[Fillable(['name', 'quantity', 'category', 'bought_at'])]
#[UseEloquentBuilder(ShoppingListItemBuilder::class)]
class ShoppingListItem extends Model
{
    /** @use HasFactory<ShoppingListItemFactory> */
    use HasFactory;

    protected $attributes = [
        'category' => 'other',
    ];

    protected static function booted(): void
    {
        static::saving(function (ShoppingListItem $item): void {
            if ($item->exists && ! $item->isDirty('name')) {
                return;
            }

            $ingredient = Ingredient::query()->named($item->name)->first();

            $item->ingredient()->associate($ingredient);

            if ($ingredient !== null) {
                $item->name = $ingredient->name;
                $item->category = $ingredient->category ?? $item->category;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'category' => IngredientCategory::class,
            'bought_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Ingredient, $this>
     */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
