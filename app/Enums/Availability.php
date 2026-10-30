<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Whether a recipe's ingredient is in the kitchen. Running low still counts as having it.
 */
enum Availability: string implements HasColor, HasLabel
{
    case OnHand = 'on_hand';
    case Low = 'low';
    case Out = 'out';

    public function getLabel(): string
    {
        return match ($this) {
            self::OnHand => 'On hand',
            self::Low => 'Running low',
            self::Out => 'Out',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::OnHand => 'success',
            self::Low => 'warning',
            self::Out => 'danger',
        };
    }
}
