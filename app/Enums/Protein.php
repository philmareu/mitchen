<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * A recipe's main protein, for keeping what's cooked varied ("chicken three times this week").
 * A recipe without one (a salsa, a dessert) has none set.
 */
enum Protein: string implements HasLabel
{
    case Beef = 'beef';
    case Pork = 'pork';
    case Chicken = 'chicken';
    case Turkey = 'turkey';
    case Lamb = 'lamb';
    case Fish = 'fish';
    case Shellfish = 'shellfish';
    case Beans = 'beans';
    case Tofu = 'tofu';
    case Eggs = 'eggs';

    public function getLabel(): string
    {
        return match ($this) {
            self::Beef => 'Beef',
            self::Pork => 'Pork',
            self::Chicken => 'Chicken',
            self::Turkey => 'Turkey',
            self::Lamb => 'Lamb',
            self::Fish => 'Fish',
            self::Shellfish => 'Shellfish',
            self::Beans => 'Beans or lentils',
            self::Tofu => 'Tofu',
            self::Eggs => 'Eggs',
        };
    }
}
