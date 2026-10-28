<?php

namespace App\Support;

use App\Enums\StockLevel;
use App\Enums\Unit;

/**
 * Turns quick, kitchen-style notes into a Quantity.
 *
 * Reads exact amounts ("2 lb", "500g", "1 1/2 cups", "1½ cups"), spoken ones
 * ("half a jar", "a couple cans", "one and a half cups", "half a dozen") and
 * rough stock levels ("low", "almost out", "plenty").
 */
final class QuantityParser
{
    private const MAX_AMOUNT = 999_999;

    private const UNICODE_FRACTIONS = [
        '⅛' => '1/8', '¼' => '1/4', '⅓' => '1/3', '⅜' => '3/8', '½' => '1/2',
        '⅝' => '5/8', '⅔' => '2/3', '¾' => '3/4', '⅞' => '7/8', '⁄' => '/',
    ];

    private const NUMBER_WORDS = [
        'a' => 1, 'an' => 1, 'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6,
        'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10, 'eleven' => 11, 'twelve' => 12,
    ];

    /** Longest first, so "three quarters" wins over "quarter". */
    private const FRACTION_WORDS = [
        'three quarters' => 3 / 4, 'three-quarters' => 3 / 4,
        'two thirds' => 2 / 3, 'two-thirds' => 2 / 3,
        'a quarter' => 1 / 4, 'one quarter' => 1 / 4, 'quarter' => 1 / 4,
        'a third' => 1 / 3, 'one third' => 1 / 3, 'third' => 1 / 3,
        'a half' => 1 / 2, 'half' => 1 / 2,
    ];

    private const VAGUE_COUNTS = ['couple' => 2, 'few' => 3];

    /** Words that mean "no unit, just count them". */
    private const COUNT_WORDS = ['each', 'ea', 'whole', 'count', 'ct', 'item', 'items', 'x'];

    private const FILLER_PREFIX = '/^(?:about|around|roughly|approximately|approx|maybe|like|only|just|~)\s*/';

    private const FILLER_SUFFIX = '/\s+(?:left|remaining|left over|leftover|on hand|in stock)$/';

    /** @var array<string, Unit>|null */
    private static ?array $unitAliases = null;

    /** @var array<string, StockLevel>|null */
    private static ?array $levelPhrases = null;

    /**
     * @throws UnreadableQuantity
     */
    public function parse(?string $text): Quantity
    {
        $normalized = $this->normalize($text ?? '');

        if ($normalized === '') {
            return Quantity::unknown();
        }

        if ($level = self::levelPhrases()[$normalized] ?? null) {
            return Quantity::level($level);
        }

        [$amount, $rest] = $this->readAmount($normalized);
        [$amount, $unit] = $this->readUnit($amount, $rest, $text ?? '');

        if ($amount > self::MAX_AMOUNT) {
            throw UnreadableQuantity::forText($text ?? '');
        }

        return Quantity::of($amount, $unit);
    }

    private function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = strtr($text, array_map(fn (string $fraction): string => " {$fraction} ", self::UNICODE_FRACTIONS));
        $text = str_replace(' / ', '/', $text);
        $text = (string) preg_replace('/\s+/', ' ', $text);
        $text = rtrim(trim($text), '.!,;');

        do {
            $before = $text;
            $text = trim((string) preg_replace(self::FILLER_PREFIX, '', $text));
            $text = trim((string) preg_replace(self::FILLER_SUFFIX, '', $text));
        } while ($text !== $before);

        return $text;
    }

    /**
     * Read the leading amount, returning it with whatever text follows.
     *
     * @return array{0: float|null, 1: string}
     */
    private function readAmount(string $text): array
    {
        [$amount, $rest] = match (true) {
            (bool) preg_match('/^(\d+)[\s-]+(\d+)\/(\d+)/', $text, $m) => [(int) $m[1] + $this->divide($m[2], $m[3]), $m],
            (bool) preg_match('/^(\d+)\/(\d+)/', $text, $m) => [$this->divide($m[1], $m[2]), $m],
            (bool) preg_match('/^(\d*\.\d+|\d+)/', $text, $m) => [(float) $m[1], $m],
            (bool) preg_match($this->fractionWordsPattern(), $text, $m) => [self::FRACTION_WORDS[$m[1]], $m],
            (bool) preg_match('/^(?:a\s+)?(couple|few)(?:\s+of)?(?=\s|$)/', $text, $m) => [(float) self::VAGUE_COUNTS[$m[1]], $m],
            (bool) preg_match('/^('.implode('|', array_keys(self::NUMBER_WORDS)).')(?=\s|$)/', $text, $m) => [(float) self::NUMBER_WORDS[$m[1]], $m],
            default => [null, null],
        };

        if ($amount === null || $rest === null) {
            return [null, $text];
        }

        $rest = trim(substr($text, strlen($rest[0])));

        // "one and a half", "2 and 1/2"
        if (preg_match('/^and\s+(?:a\s+|one\s+)?(half|quarter|third)(?=\s|$)/', $rest, $m)) {
            $amount += ['half' => 1 / 2, 'quarter' => 1 / 4, 'third' => 1 / 3][$m[1]];
            $rest = trim(substr($rest, strlen($m[0])));
        } elseif (preg_match('/^and\s+(\d+)\/(\d+)/', $rest, $m)) {
            $amount += $this->divide($m[1], $m[2]);
            $rest = trim(substr($rest, strlen($m[0])));
        }

        return [$amount, $rest];
    }

    /**
     * @return array{0: float, 1: Unit|null}
     */
    private function readUnit(?float $amount, string $rest, string $original): array
    {
        // "2 jars of peanut butter" -> "2 jars"
        $rest = trim((string) preg_replace('/(^|\s)of(\s.*)?$/', '', $rest));

        if (preg_match('/^(?:a\s+)?dozen(?=\s|$)/', $rest, $m)) {
            $amount = ($amount ?? 1) * 12;
            $rest = trim(substr($rest, strlen($m[0])));
        }

        if ($rest === '' || in_array($rest, self::COUNT_WORDS, true)) {
            if ($amount === null) {
                throw UnreadableQuantity::forText($original);
            }

            return [$amount, null];
        }

        $unit = self::unitAliases()[$rest] ?? null;

        if ($unit === null) {
            throw $amount === null
                ? UnreadableQuantity::forText($original)
                : UnreadableQuantity::unknownUnit($rest);
        }

        // A bare unit ("jar") means one of it.
        return [$amount ?? 1, $unit];
    }

    private function divide(string $numerator, string $denominator): float
    {
        return (int) $denominator === 0 ? 0 : (int) $numerator / (int) $denominator;
    }

    private function fractionWordsPattern(): string
    {
        $words = implode('|', array_map(fn (string $word): string => preg_quote($word, '/'), array_keys(self::FRACTION_WORDS)));

        return '/^('.$words.')(?:\s+of)?(?:\s+(?:a|an|the))?(?=\s|$)/';
    }

    /**
     * @return array<string, Unit>
     */
    private static function unitAliases(): array
    {
        if (self::$unitAliases === null) {
            self::$unitAliases = [];

            foreach (Unit::cases() as $unit) {
                foreach ($unit->aliases() as $alias) {
                    self::$unitAliases[$alias] = $unit;
                }
            }
        }

        return self::$unitAliases;
    }

    /**
     * @return array<string, StockLevel>
     */
    private static function levelPhrases(): array
    {
        if (self::$levelPhrases === null) {
            self::$levelPhrases = [];

            foreach (StockLevel::cases() as $level) {
                foreach ($level->phrases() as $phrase) {
                    self::$levelPhrases[$phrase] = $level;
                }
            }
        }

        return self::$levelPhrases;
    }
}
