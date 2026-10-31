<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum EquipmentCategory: string implements HasLabel
{
    /** Stand mixer, blender, slow cooker, pressure cooker, sous vide circulator, dehydrator. */
    case Appliances = 'appliances';

    /** Pots, pans, skillets, Dutch ovens, woks. */
    case Cookware = 'cookware';

    /** Sheet pans, loaf and cake pans, muffin tins, baking dishes. */
    case Bakeware = 'bakeware';

    /** Knives, cutting boards, sharpeners. */
    case Knives = 'knives';

    /** Ladles, spatulas, whisks, tongs, wooden spoons, peelers. */
    case Utensils = 'utensils';

    /** Single-job tools: ricer, tortilla press, mandoline, garlic press, thermometer. */
    case Tools = 'tools';

    /** Mixing bowls, measuring cups and spoons, scales, colanders, sieves. */
    case Prep = 'prep';

    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Appliances => 'Appliances',
            self::Cookware => 'Cookware',
            self::Bakeware => 'Bakeware',
            self::Knives => 'Knives & boards',
            self::Utensils => 'Utensils',
            self::Tools => 'Tools & gadgets',
            self::Prep => 'Bowls & measuring',
            self::Other => 'Other',
        };
    }
}
