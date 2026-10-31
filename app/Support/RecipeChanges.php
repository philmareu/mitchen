<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * What changed between two versions of a recipe (Recipe::snapshot()), in plain words:
 * "Kosher salt: 1 tsp → 1½ tsp", "Added Black pepper (½ tsp)", "Changed step 2: …".
 * Ingredients and equipment are matched by name, ignoring case and order; steps in order.
 */
final class RecipeChanges
{
    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<string>
     */
    public static function between(array $before, array $after): array
    {
        return [
            ...self::servings($before['servings'] ?? null, $after['servings'] ?? null),
            ...self::ingredients(self::rows($before['ingredients'] ?? []), self::rows($after['ingredients'] ?? [])),
            ...self::substitutes(self::rows($before['substitutes'] ?? []), self::rows($after['substitutes'] ?? [])),
            ...self::variants(self::rows($before['variants'] ?? []), self::rows($after['variants'] ?? [])),
            ...self::equipment(self::texts($before['equipment'] ?? []), self::texts($after['equipment'] ?? [])),
            ...self::steps(self::texts($before['steps'] ?? []), self::texts($after['steps'] ?? [])),
        ];
    }

    /**
     * @return list<string>
     */
    private static function servings(mixed $before, mixed $after): array
    {
        return $before == $after ? [] : ['Serves or makes: '.($before ?? '—').' → '.($after ?? '—')];
    }

    /**
     * @param  list<array<string, mixed>>  $before
     * @param  list<array<string, mixed>>  $after
     * @return list<string>
     */
    private static function ingredients(array $before, array $after): array
    {
        $before = self::keyedByName($before);
        $after = self::keyedByName($after);
        $changes = [];

        foreach ($after as $key => $line) {
            $old = $before[$key] ?? null;

            if ($old === null) {
                $changes[] = 'Added '.$line['name'].(filled($line['quantity'] ?? null) ? ' ('.$line['quantity'].')' : '');

                continue;
            }

            $differences = array_filter([
                ($old['quantity'] ?? null) === ($line['quantity'] ?? null) ? null : ($old['quantity'] ?? 'no amount').' → '.($line['quantity'] ?? 'no amount'),
                ($old['note'] ?? null) === ($line['note'] ?? null) ? null : match (true) {
                    blank($line['note'] ?? null) => 'no longer '.$old['note'],
                    blank($old['note'] ?? null) => $line['note'],
                    default => $old['note'].' → '.$line['note'],
                },
                (bool) ($old['is_optional'] ?? false) === (bool) ($line['is_optional'] ?? false) ? null : (($line['is_optional'] ?? false) ? 'now optional' : 'no longer optional'),
            ]);

            if ($differences !== []) {
                $changes[] = $line['name'].': '.implode(', ', $differences);
            }
        }

        foreach (array_diff_key($before, $after) as $line) {
            $changes[] = 'Removed '.$line['name'];
        }

        return $changes;
    }

    /**
     * Substitutes are compared by the ingredient they stand in for.
     *
     * @param  list<array<string, mixed>>  $before
     * @param  list<array<string, mixed>>  $after
     * @return list<string>
     */
    private static function substitutes(array $before, array $after): array
    {
        $before = self::describedByIngredient($before);
        $after = self::describedByIngredient($after);
        $changes = [];

        foreach ($after as $key => [$name, $description]) {
            $changes[] = match ($before[$key][1] ?? null) {
                null => 'Added a substitute for '.$name.': '.$description,
                $description => null,
                default => 'Changed the substitute for '.$name.': '.$description,
            };
        }

        foreach (array_diff_key($before, $after) as [$name]) {
            $changes[] = 'Removed the substitute for '.$name;
        }

        return array_values(array_filter($changes));
    }

    /**
     * Variants are compared by name.
     *
     * @param  list<array<string, mixed>>  $before
     * @param  list<array<string, mixed>>  $after
     * @return list<string>
     */
    private static function variants(array $before, array $after): array
    {
        $describe = function (array $variants): array {
            $described = [];

            foreach ($variants as $variant) {
                $name = trim((string) ($variant['name'] ?? ''));
                $described[mb_strtolower($name)] = [$name, implode(', ', array_map(
                    fn (array $line): string => ($line['is_left_out'] ?? false) ? 'no '.($line['name'] ?? '') : trim(($line['quantity'] ?? '').' '.($line['name'] ?? '')),
                    self::rows($variant['ingredients'] ?? []),
                )).(filled($variant['notes'] ?? null) ? ' ('.$variant['notes'].')' : '')];
            }

            return $described;
        };

        $before = $describe($before);
        $after = $describe($after);
        $changes = [];

        foreach ($after as $key => [$name, $description]) {
            $changes[] = match ($before[$key][1] ?? null) {
                null => 'Added the '.$name.' variant: '.$description,
                $description => null,
                default => 'Changed the '.$name.' variant: '.$description,
            };
        }

        foreach (array_diff_key($before, $after) as [$name]) {
            $changes[] = 'Removed the '.$name.' variant';
        }

        return array_values(array_filter($changes));
    }

    /**
     * Each ingredient's substitutes as one description ("1 cup Milk + 1 tbsp Lemon juice"),
     * by lowercase name.
     *
     * @param  list<array<string, mixed>>  $substitutes
     * @return array<string, array{string, string}>
     */
    private static function describedByIngredient(array $substitutes): array
    {
        $described = [];

        foreach ($substitutes as $substitute) {
            $name = trim((string) ($substitute['instead_of'] ?? ''));
            $description = implode(' + ', array_map(
                fn (array $line): string => trim(($line['quantity'] ?? '').' '.($line['name'] ?? '')),
                self::rows($substitute['ingredients'] ?? []),
            )).(filled($substitute['note'] ?? null) ? ' ('.$substitute['note'].')' : '');

            $key = mb_strtolower($name);
            $described[$key] = [$name, isset($described[$key]) ? $described[$key][1].' / or '.$description : $description];
        }

        return $described;
    }

    /**
     * @param  list<string>  $before
     * @param  list<string>  $after
     * @return list<string>
     */
    private static function equipment(array $before, array $after): array
    {
        $lower = fn (array $names): array => array_map(mb_strtolower(...), $names);

        return [
            ...array_map(fn (string $name): string => 'Added '.$name, array_values(array_filter($after, fn (string $name): bool => ! in_array(mb_strtolower($name), $lower($before), true)))),
            ...array_map(fn (string $name): string => 'Removed '.$name, array_values(array_filter($before, fn (string $name): bool => ! in_array(mb_strtolower($name), $lower($after), true)))),
        ];
    }

    /**
     * Steps kept in place are matched up (longest common subsequence); between them, a removed
     * step and an added one count as a change.
     *
     * @param  list<string>  $before
     * @param  list<string>  $after
     * @return list<string>
     */
    private static function steps(array $before, array $after): array
    {
        $lengths = array_fill(0, count($before) + 1, array_fill(0, count($after) + 1, 0));

        for ($i = count($before) - 1; $i >= 0; $i--) {
            for ($j = count($after) - 1; $j >= 0; $j--) {
                $lengths[$i][$j] = $before[$i] === $after[$j]
                    ? $lengths[$i + 1][$j + 1] + 1
                    : max($lengths[$i + 1][$j], $lengths[$i][$j + 1]);
            }
        }

        // Steps removed and added between two kept ones, by gap.
        $removedIn = [];
        $addedIn = [];
        $gap = 0;
        [$i, $j] = [0, 0];

        while ($i < count($before) || $j < count($after)) {
            if ($i < count($before) && $j < count($after) && $before[$i] === $after[$j]) {
                $gap++;
                $i++;
                $j++;
            } elseif ($j < count($after) && ($i === count($before) || $lengths[$i][$j + 1] >= $lengths[$i + 1][$j])) {
                $addedIn[$gap][] = $j++;
            } else {
                $removedIn[$gap][] = $i++;
            }
        }

        $changes = [];

        for ($index = 0; $index <= $gap; $index++) {
            $removed = $removedIn[$index] ?? [];
            $added = $addedIn[$index] ?? [];

            foreach ($added as $position => $step) {
                $changes[] = (isset($removed[$position]) ? 'Changed step ' : 'Added step ').($step + 1).': '.Str::limit($after[$step], 80);
            }

            foreach (array_slice($removed, count($added)) as $step) {
                $changes[] = 'Removed step '.($step + 1).': '.Str::limit($before[$step], 80);
            }
        }

        return $changes;
    }

    /**
     * Lines by lowercase name; a second line of the same name gets its own key.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, array<string, mixed>>
     */
    private static function keyedByName(array $lines): array
    {
        $keyed = [];

        foreach ($lines as $line) {
            $key = mb_strtolower(trim((string) ($line['name'] ?? '')));

            while (isset($keyed[$key])) {
                $key .= '#';
            }

            $keyed[$key] = $line;
        }

        return $keyed;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rows(mixed $rows): array
    {
        return is_array($rows) ? array_values(array_filter($rows, is_array(...))) : [];
    }

    /**
     * @return list<string>
     */
    private static function texts(mixed $texts): array
    {
        return is_array($texts) ? array_values(array_filter($texts, is_string(...))) : [];
    }
}
