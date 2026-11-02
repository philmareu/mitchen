<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Names compared the way a cook means them: ignoring case, surrounding spaces and
 * whether the last word is singular or plural, so a recipe's "Lime" is the kitchen's "Limes".
 */
class Names
{
    /**
     * Whether two names have the same singular or the same plural. Both, because the inflector
     * gets some of each wrong: "hummus" singularizes to "hummu", "mango" pluralizes to "mangos".
     */
    public static function same(string $name, string $other): bool
    {
        return array_intersect_assoc(self::forms($name), self::forms($other)) !== [];
    }

    /**
     * The key of the listed name that's the same as this one, else null. The same spelling wins
     * over a singular or plural: with "Lime" and "Limes" both listed, "lime" is "Lime".
     *
     * @template TKey of array-key
     *
     * @param  Collection<TKey, string>  $names
     * @return TKey|null
     */
    public static function search(string $name, Collection $names): int|string|null
    {
        $key = $names->search(fn (string $listed): bool => mb_strtolower(trim($listed)) === mb_strtolower(trim($name)));

        if ($key === false) {
            $key = $names->search(fn (string $listed): bool => self::same($listed, $name));
        }

        return $key === false ? null : $key;
    }

    /**
     * The name in lowercase with its last word made singular, then plural. Only the last word,
     * because the inflector's irregular words ("leaf" to "leaves") only match a whole string.
     *
     * @return array{singular: string, plural: string}
     */
    private static function forms(string $name): array
    {
        $name = mb_strtolower(trim($name));
        $word = Str::afterLast($name, ' ');
        $start = mb_substr($name, 0, mb_strlen($name) - mb_strlen($word));

        return [
            'singular' => $start.Str::singular($word),
            'plural' => $start.Str::plural($word),
        ];
    }
}
