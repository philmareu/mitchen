<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum IngredientCategory: string implements HasLabel
{
    case Produce = 'produce';
    case DairyAndEggs = 'dairy_and_eggs';
    case MeatAndSeafood = 'meat_and_seafood';
    case Bakery = 'bakery';
    case GrainsAndPasta = 'grains_and_pasta';
    case Baking = 'baking';
    case SpicesAndHerbs = 'spices_and_herbs';
    case OilsAndCondiments = 'oils_and_condiments';
    case CannedAndJarred = 'canned_and_jarred';
    case Frozen = 'frozen';
    case Snacks = 'snacks';
    case Beverages = 'beverages';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Produce => 'Produce',
            self::DairyAndEggs => 'Dairy & eggs',
            self::MeatAndSeafood => 'Meat & seafood',
            self::Bakery => 'Bakery',
            self::GrainsAndPasta => 'Grains & pasta',
            self::Baking => 'Baking',
            self::SpicesAndHerbs => 'Spices & herbs',
            self::OilsAndCondiments => 'Oils & condiments',
            self::CannedAndJarred => 'Canned & jarred',
            self::Frozen => 'Frozen',
            self::Snacks => 'Snacks',
            self::Beverages => 'Beverages',
            self::Other => 'Other',
        };
    }

    /**
     * Where something from this aisle usually goes, when nothing on hand says otherwise.
     */
    public function usualLocation(): StorageLocation
    {
        return match ($this) {
            self::Produce, self::DairyAndEggs, self::MeatAndSeafood => StorageLocation::Fridge,
            self::Frozen => StorageLocation::Freezer,
            self::SpicesAndHerbs => StorageLocation::SpiceRack,
            self::Bakery => StorageLocation::Counter,
            default => StorageLocation::Pantry,
        };
    }
}
