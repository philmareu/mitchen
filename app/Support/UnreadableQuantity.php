<?php

namespace App\Support;

use InvalidArgumentException;

class UnreadableQuantity extends InvalidArgumentException
{
    private const EXAMPLES = 'Try something like "2 lb", "half a jar", "3 cans" or "low".';

    public static function forText(string $text): self
    {
        return new self(sprintf('Couldn\'t read "%s" as a quantity. %s', trim($text), self::EXAMPLES));
    }

    public static function unknownUnit(string $unit): self
    {
        return new self(sprintf('"%s" isn\'t a unit Mitchen knows. %s', $unit, self::EXAMPLES));
    }
}
