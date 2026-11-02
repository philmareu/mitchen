<?php

namespace App\Models\Builders;

use App\Models\ShoppingListItem;
use App\Support\Names;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends Builder<ShoppingListItem>
 */
class ShoppingListItemBuilder extends Builder
{
    /**
     * The entry with this name, ignoring case, surrounding spaces and singular or plural (Names).
     */
    public function named(string $name): static
    {
        return $this->whereKey(Names::search($name, (clone $this)->pluck('name', 'id')) ?? []);
    }

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
