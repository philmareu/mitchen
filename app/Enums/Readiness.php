<?php

namespace App\Enums;

use BackedEnum;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * How close the kitchen is to making a recipe, closest first: everything's here, a few things
 * from any store, a bigger shop, something that takes planning to get (hard to find, out of
 * season or made ahead), or equipment the kitchen hasn't got.
 */
enum Readiness: string implements HasColor, HasIcon, HasLabel
{
    case Ready = 'ready';
    case QuickShop = 'quick_shop';
    case BigShop = 'big_shop';
    case PlanAhead = 'plan_ahead';
    case NeedsEquipment = 'needs_equipment';

    public function getLabel(): string
    {
        return match ($this) {
            self::Ready => 'Ready to cook',
            self::QuickShop => 'A quick shop',
            self::BigShop => 'A bigger shop',
            self::PlanAhead => 'Plan ahead',
            self::NeedsEquipment => 'Needs equipment',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Ready => 'success',
            self::QuickShop => 'info',
            self::BigShop => 'warning',
            self::PlanAhead => 'primary',
            self::NeedsEquipment => 'danger',
        };
    }

    public function getIcon(): BackedEnum
    {
        return match ($this) {
            self::Ready => Heroicon::OutlinedCheckCircle,
            self::QuickShop => Heroicon::OutlinedShoppingBag,
            self::BigShop => Heroicon::OutlinedShoppingCart,
            self::PlanAhead => Heroicon::OutlinedCalendarDays,
            self::NeedsEquipment => Heroicon::OutlinedWrenchScrewdriver,
        };
    }

    public function isCloserThan(self $other): bool
    {
        return $this->rank() < $other->rank();
    }

    public function rank(): int
    {
        return (int) array_search($this, self::cases(), true);
    }
}
