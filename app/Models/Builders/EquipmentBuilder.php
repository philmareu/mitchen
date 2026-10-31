<?php

namespace App\Models\Builders;

use App\Models\Equipment;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends Builder<Equipment>
 */
class EquipmentBuilder extends Builder
{
    /**
     * Equipment with this name, ignoring case and surrounding spaces.
     */
    public function named(string $name): static
    {
        return $this->whereRaw('lower(name) = ?', [mb_strtolower(trim($name))]);
    }
}
