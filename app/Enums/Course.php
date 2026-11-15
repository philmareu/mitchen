<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * What part of a meal a recipe is, so the cook can look for "a side for this" or
 * "breakfast for dinner".
 */
enum Course: string implements HasLabel
{
    case Breakfast = 'breakfast';
    case Main = 'main';
    case Side = 'side';
    case Soup = 'soup';
    case Salad = 'salad';

    /** Salsas, dressings, spice mixes and other things made to go with something else. */
    case Sauce = 'sauce';

    /** Breads, tortillas, biscuits. */
    case Bread = 'bread';

    case Dessert = 'dessert';
    case Snack = 'snack';
    case Drink = 'drink';

    public function getLabel(): string
    {
        return match ($this) {
            self::Breakfast => 'Breakfast',
            self::Main => 'Main',
            self::Side => 'Side',
            self::Soup => 'Soup',
            self::Salad => 'Salad',
            self::Sauce => 'Sauce or condiment',
            self::Bread => 'Bread',
            self::Dessert => 'Dessert',
            self::Snack => 'Snack',
            self::Drink => 'Drink',
        };
    }
}
