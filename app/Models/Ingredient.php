<?php

namespace App\Models;

use App\Enums\IngredientCategory;
use App\Enums\Sourcing;
use App\Enums\StockLevel;
use App\Enums\Unit;
use App\Models\Builders\IngredientBuilder;
use App\Support\Quantity;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
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
 *
 * Some take planning to get more of (Sourcing): hard to find, seasonal (in the stores the
 * months in `season`) or made ahead, with `lead_days` for how long that takes.
 *
 * @property list<int>|null $season
 */
#[Fillable(['name', 'category', 'is_core', 'always_on_hand', 'sourcing', 'lead_days', 'season', 'restock_point', 'notes'])]
#[UseEloquentBuilder(IngredientBuilder::class)]
class Ingredient extends Model
{
    /** @use HasFactory<IngredientFactory> */
    use HasFactory;

    /**
     * Names that start out always on hand.
     */
    private const array ALWAYS_ON_HAND = ['water', 'tap water', 'ice', 'ice water'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'sourcing' => 'grocery',
    ];

    protected static function booted(): void
    {
        static::creating(function (Ingredient $ingredient): void {
            $ingredient->always_on_hand ??= in_array(mb_strtolower(trim($ingredient->name)), self::ALWAYS_ON_HAND, true);
        });

        static::saving(function (Ingredient $ingredient): void {
            if (! $ingredient->sourcing->hasLeadDays()) {
                $ingredient->lead_days = null;
            }

            if ($ingredient->sourcing !== Sourcing::Seasonal) {
                $ingredient->season = null;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'category' => IngredientCategory::class,
            'is_core' => 'boolean',
            'always_on_hand' => 'boolean',
            'sourcing' => Sourcing::class,
            'lead_days' => 'integer',
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
     * Whether getting more takes planning on the day (today by default): a hard-to-find or
     * made-ahead ingredient always does, a seasonal one when it's out of season.
     */
    public function needsLeadTime(?CarbonInterface $on = null): bool
    {
        return match ($this->sourcing) {
            Sourcing::Grocery => false,
            Sourcing::Seasonal => ! $this->isInSeason($on),
            default => true,
        };
    }

    /**
     * Anything but a seasonal ingredient is always in season, and so is one with no months set.
     */
    public function isInSeason(?CarbonInterface $on = null): bool
    {
        return $this->sourcing !== Sourcing::Seasonal
            || blank($this->season)
            || in_array(($on ?? today())->month, $this->season, true);
    }

    /**
     * What getting more takes, for a recipe that needs it: "make ahead, about 3 days",
     * "hard to find", "in season Jun–Aug". Null for a grocery-store ingredient.
     */
    public function leadTime(): ?string
    {
        return match ($this->sourcing) {
            Sourcing::Grocery => null,
            Sourcing::Seasonal => blank($this->season) ? 'seasonal' : 'in season '.self::formatMonths($this->season),
            default => mb_strtolower($this->sourcing->getLabel()).($this->lead_days ? ', about '.self::formatDays($this->lead_days) : ''),
        };
    }

    /**
     * "3 days", "a week", "3 weeks".
     */
    public static function formatDays(int $days): string
    {
        return match (true) {
            $days === 1 => 'a day',
            $days === 7 => 'a week',
            $days % 7 === 0 => ($days / 7).' weeks',
            default => $days.' days',
        };
    }

    /**
     * Months as runs, across the new year too: [6, 7, 8, 10] is "Jun–Aug, Oct", [12, 1, 2]
     * "Dec–Feb".
     *
     * @param  list<int>  $months
     */
    public static function formatMonths(array $months): string
    {
        if (count(array_unique($months)) >= 12) {
            return 'all year';
        }

        $name = fn (int $month): string => CarbonImmutable::create(2000, $month, 1)->format('M');
        $next = fn (int $month): int => $month % 12 + 1;
        $runs = [];

        foreach (collect($months)->unique()->sort() as $month) {
            if (in_array($month === 1 ? 12 : $month - 1, $months, true)) {
                continue;
            }

            $last = $month;

            while (in_array($next($last), $months, true)) {
                $last = $next($last);
            }

            $runs[] = $last === $month ? $name($month) : $name($month).'–'.$name($last);
        }

        return implode(', ', $runs);
    }

    /**
     * Months as a sorted list of numbers, whatever the form sends ("6" or 6).
     *
     * @return Attribute<list<int>|null, array<int|string>|null>
     */
    protected function season(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): ?array => $value === null ? null : json_decode($value, true),
            set: fn (?array $value): ?string => blank($value) ? null : json_encode(collect($value)
                ->map(fn (int|string $month): int => (int) $month)
                ->filter(fn (int $month): bool => $month >= 1 && $month <= 12)
                ->unique()
                ->sort()
                ->values()
                ->all()),
        );
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
