<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * What a note or photo says about a piece of kitchen equipment.
 */
enum EquipmentChangeKind: string implements HasLabel
{
    /** Not in the kitchen's equipment yet. */
    case Added = 'added';

    /** Already listed: its photos are added and its details updated. */
    case Existing = 'existing';

    /** Broken, given away or thrown out. */
    case Gone = 'gone';

    public function getLabel(): string
    {
        return match ($this) {
            self::Added => 'New',
            self::Existing => 'Already have',
            self::Gone => 'Gone',
        };
    }
}
