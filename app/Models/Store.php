<?php

namespace App\Models;

use App\Models\Builders\StoreBuilder;
use Database\Factories\StoreFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Somewhere the cook shops. Receipts are matched to stores by name.
 *
 * A store that takes orders online has a search_url: the address of a search for "milk" in its
 * online shop, as copied from the browser. Swapping the milk for anything else looks that up.
 */
#[Fillable(['name', 'notes', 'search_url'])]
#[UseEloquentBuilder(StoreBuilder::class)]
class Store extends Model
{
    /** @use HasFactory<StoreFactory> */
    use HasFactory;

    /**
     * What the cook searches the online shop for, so its address shows where the search goes.
     */
    public const string SEARCH_SAMPLE = 'milk';

    /**
     * @return HasMany<Purchase, $this>
     */
    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    public function shopsOnline(): bool
    {
        return $this->searchUrl(self::SEARCH_SAMPLE) !== null;
    }

    /**
     * Where the online shop lists what it has for this: the search address with the last "milk"
     * in it swapped (a shop at milkmarket.com keeps its name). Null when the store has no
     * online shop, or its address isn't a web address with the sample search in it.
     */
    public function searchUrl(string $item): ?string
    {
        $address = (string) $this->search_url;
        $at = mb_strripos($address, self::SEARCH_SAMPLE);

        if ($at === false || ! Str::isUrl($address, ['http', 'https'])) {
            return null;
        }

        return mb_substr($address, 0, $at).rawurlencode(trim($item)).mb_substr($address, $at + mb_strlen(self::SEARCH_SAMPLE));
    }
}
