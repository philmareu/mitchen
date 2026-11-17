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
     * @param  Collection<TKey, covariant string>  $names
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
     * Listed names that may mean the same thing as this one, for the cook to check, never to link
     * by themselves: a slip of a letter or two ("cilantor" for "Cilantro"), or the same last word
     * ("White onion" and "Yellow onion", "Salt" and "Kosher salt"). The same name isn't listed.
     *
     * @param  Collection<array-key, string>  $names
     * @return list<string>
     */
    public static function close(string $name, Collection $names, int $limit = 5): array
    {
        $name = mb_strtolower(trim($name));
        $lastWord = Str::afterLast($name, ' ');

        return $names
            ->reject(fn (string $listed): bool => self::same($listed, $name))
            ->filter(function (string $listed) use ($name, $lastWord): bool {
                $listed = mb_strtolower(trim($listed));
                $typos = min(mb_strlen($name), mb_strlen($listed)) >= 5 ? 2 : 1;

                return levenshtein($name, $listed) <= $typos
                    || self::same(Str::afterLast($listed, ' '), $lastWord);
            })
            ->take($limit)
            ->values()
            ->all();
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
