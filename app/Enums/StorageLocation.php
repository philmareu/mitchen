<?php

namespace App\Enums;

use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum StorageLocation: string implements HasIcon, HasLabel
{
    case Pantry = 'pantry';
    case Fridge = 'fridge';
    case Freezer = 'freezer';
    case SpiceRack = 'spice_rack';
    case Counter = 'counter';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pantry => 'Pantry',
            self::Fridge => 'Fridge',
            self::Freezer => 'Freezer',
            self::SpiceRack => 'Spice rack',
            self::Counter => 'Counter',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Pantry => Heroicon::OutlinedArchiveBox,
            self::Fridge => Heroicon::OutlinedCube,
            self::Freezer => Heroicon::OutlinedSparkles,
            self::SpiceRack => Heroicon::OutlinedBeaker,
            self::Counter => Heroicon::OutlinedHome,
        };
    }
}
