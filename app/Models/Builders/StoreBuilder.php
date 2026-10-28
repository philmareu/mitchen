<?php

namespace App\Models\Builders;

use App\Models\Store;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends Builder<Store>
 */
class StoreBuilder extends Builder
{
    /**
     * The store with this name, ignoring case and surrounding spaces.
     */
    public function named(string $name): static
    {
        return $this->whereRaw('lower(name) = ?', [mb_strtolower(trim($name))]);
    }
}
