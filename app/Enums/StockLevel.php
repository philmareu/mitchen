<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * A rough reading of how much is left, for when an exact amount isn't worth measuring.
 */
enum StockLevel: string implements HasColor, HasLabel
{
    case Full = 'full';
    case Half = 'half';
    case Low = 'low';
    case Out = 'out';

    public function getLabel(): string
    {
        return match ($this) {
            self::Full => 'Full',
            self::Half => 'About half',
            self::Low => 'Low',
            self::Out => 'Out',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Full => 'success',
            self::Half => 'info',
            self::Low => 'warning',
            self::Out => 'danger',
        };
    }

    public function needsRestock(): bool
    {
        return in_array($this, [self::Low, self::Out], true);
    }

    public function isAbove(self $other): bool
    {
        $order = array_flip([self::Out->value, self::Low->value, self::Half->value, self::Full->value]);

        return $order[$this->value] > $order[$other->value];
    }

    /**
     * Phrases a person might type for each level, matched after lowercasing and trimming.
     *
     * @return list<string>
     */
    public function phrases(): array
    {
        return match ($this) {
            self::Full => ['full', 'plenty', 'lots', 'a lot', 'stocked', 'well stocked', 'unopened', 'new', 'sealed'],
            self::Half => ['half', 'about half', 'half full', 'half left', 'some', 'partial', 'opened', 'half empty'],
            self::Low => ['low', 'running low', 'getting low', 'almost out', 'nearly out', 'almost gone', 'nearly gone', 'a little', 'a bit', 'not much', 'little', 'barely any'],
            self::Out => ['out', 'empty', 'none', 'gone', 'all gone', 'used up', 'ran out', 'run out', 'out of it', 'out of stock', 'finished', 'zero', '0'],
        };
    }
}
