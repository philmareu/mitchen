<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Whether a recipe's ingredient is in the kitchen. Running low still counts as having it;
 * ripening (there, but not ready until a date) doesn't.
 */
enum Availability: string implements HasColor, HasLabel
{
    case OnHand = 'on_hand';
    case Low = 'low';
    case Ripening = 'ripening';
    case Out = 'out';

    /**
     * The recipe can't use it today.
     */
    public function isMissing(): bool
    {
        return $this === self::Out || $this === self::Ripening;
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::OnHand => 'On hand',
            self::Low => 'Running low',
            self::Ripening => 'Ripening',
            self::Out => 'Out',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::OnHand => 'success',
            self::Low => 'warning',
            self::Ripening => 'info',
            self::Out => 'danger',
        };
    }
}
