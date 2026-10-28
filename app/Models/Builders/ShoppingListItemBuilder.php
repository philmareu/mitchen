<?php

namespace App\Models\Builders;

use App\Models\ShoppingListItem;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends Builder<ShoppingListItem>
 */
class ShoppingListItemBuilder extends Builder
{
    public function toBuy(): static
    {
        return $this->whereNull('bought_at');
    }

    /**
     * Ticked off: in the cart, waiting to be put away.
     */
    public function bought(): static
    {
        return $this->whereNotNull('bought_at');
    }
}
