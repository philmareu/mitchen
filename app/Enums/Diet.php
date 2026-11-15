<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Dietary needs a recipe meets as written. Several can apply.
 */
enum Diet: string implements HasLabel
{
    case Vegetarian = 'vegetarian';
    case Vegan = 'vegan';
    case GlutenFree = 'gluten_free';
    case DairyFree = 'dairy_free';

    public function getLabel(): string
    {
        return match ($this) {
            self::Vegetarian => 'Vegetarian',
            self::Vegan => 'Vegan',
            self::GlutenFree => 'Gluten-free',
            self::DairyFree => 'Dairy-free',
        };
    }
}
