<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * What a change to the kitchen says happened to an ingredient, or that it's needed.
 */
enum ChangeKind: string implements HasLabel
{
    /** New supply came in: bought, picked up, made a batch of. */
    case Bought = 'bought';

    /** How much is left now: "half a bag left", "low on olive oil". */
    case Set = 'set';

    /** Finished, empty or thrown away. */
    case UsedUp = 'used_up';

    /** To get on the next shop: goes on the shopping list, inventory unchanged. */
    case Need = 'need';

    public function getLabel(): string
    {
        return match ($this) {
            self::Bought => 'Bought',
            self::Set => 'Now have',
            self::UsedUp => 'Used up',
            self::Need => 'Need to buy',
        };
    }
}
