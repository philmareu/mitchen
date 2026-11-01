<?php

namespace App\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

/**
 * How the cook gets more of an ingredient. Anything but a grocery-store one takes planning
 * when it's out: a special trip or an order, waiting for its season, or days of fermenting
 * or drying a batch.
 */
enum Sourcing: string implements HasDescription, HasLabel
{
    case Grocery = 'grocery';
    case HardToFind = 'hard_to_find';
    case Seasonal = 'seasonal';
    case MakeAhead = 'make_ahead';

    public function getLabel(): string
    {
        return match ($this) {
            self::Grocery => 'Any grocery store',
            self::HardToFind => 'Hard to find',
            self::Seasonal => 'Seasonal',
            self::MakeAhead => 'Make ahead',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Grocery => 'Whenever you shop.',
            self::HardToFind => 'A special trip or an order online.',
            self::Seasonal => 'Only in the stores some months of the year.',
            self::MakeAhead => 'Homemade, and a batch takes time: pickling, fermenting, drying.',
        };
    }

    /**
     * Whether it takes days to get or make, which the cook can say.
     */
    public function hasLeadDays(): bool
    {
        return in_array($this, [self::HardToFind, self::MakeAhead], true);
    }
}
