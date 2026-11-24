<?php

namespace App\Support;

use App\Enums\StockLevel;
use App\Enums\Unit;
use InvalidArgumentException;
use Stringable;

/**
 * How much of something is on hand: an exact amount ("½ jar", "500 g", "3"),
 * a rough stock level ("low"), or nothing recorded at all.
 *
 * An amount of zero is stored as StockLevel::Out so "out" has a single meaning.
 */
final readonly class Quantity implements Stringable
{
    private const FRACTIONS = [
        '⅛' => 1 / 8,
        '¼' => 1 / 4,
        '⅓' => 1 / 3,
        '⅜' => 3 / 8,
        '½' => 1 / 2,
        '⅝' => 5 / 8,
        '⅔' => 2 / 3,
        '¾' => 3 / 4,
        '⅞' => 7 / 8,
    ];

    private function __construct(
        public ?float $amount,
        public ?Unit $unit,
        public ?StockLevel $level,
    ) {}

    public static function unknown(): self
    {
        return new self(null, null, null);
    }

    public static function of(float $amount, ?Unit $unit = null): self
    {
        if ($amount < 0) {
            throw new InvalidArgumentException('A quantity cannot be negative.');
        }

        if ($amount == 0) {
            return self::level(StockLevel::Out);
        }

        return new self($amount, $unit, null);
    }

    public static function level(StockLevel $level): self
    {
        return new self(null, null, $level);
    }

    /**
     * Rebuild a quantity from the columns it is stored in.
     */
    public static function fromStorage(float|string|null $amount, ?Unit $unit, ?StockLevel $level): self
    {
        if ($level !== null) {
            return self::level($level);
        }

        if ($amount === null) {
            return self::unknown();
        }

        return self::of((float) $amount, $unit);
    }

    /**
     * @throws UnreadableQuantity
     */
    public static function from(self|StockLevel|string|null $value): self
    {
        return match (true) {
            $value instanceof self => $value,
            $value instanceof StockLevel => self::level($value),
            default => self::parse($value),
        };
    }

    /**
     * Read whatever a person typed: "2 lb", "half a jar", "1½ cups", "low".
     *
     * @throws UnreadableQuantity
     */
    public static function parse(?string $text): self
    {
        return (new QuantityParser)->parse($text);
    }

    public function isUnknown(): bool
    {
        return $this->amount === null && $this->level === null;
    }

    public function isMeasured(): bool
    {
        return $this->amount !== null;
    }

    public function needsRestock(): bool
    {
        return $this->level?->needsRestock() ?? false;
    }

    /**
     * Two amounts added together in this one's unit ("1 lb" + "8 oz" is "1½ lb"),
     * or null when they can't be combined.
     */
    public function plus(self $other): ?self
    {
        $extra = $other->amountIn($this->unit);

        if ($this->amount === null || $extra === null) {
            return null;
        }

        return self::of($this->amount + $extra, $this->unit);
    }

    /**
     * This amount multiplied, in the same unit ("1½ cups" × ½ is "¾ cup"). A stock level or
     * an unknown quantity stays as it is.
     */
    public function times(float $factor): self
    {
        return $this->amount === null ? $this : self::of($this->amount * $factor, $this->unit);
    }

    /**
     * This amount in another unit, or null when it isn't measured or can't be converted.
     * Plain counts only compare with plain counts, containers only with the same container.
     */
    public function amountIn(?Unit $unit): ?float
    {
        if ($this->amount === null) {
            return null;
        }

        if ($this->unit === null || $unit === null) {
            return $this->unit === $unit ? $this->amount : null;
        }

        return $this->unit->convert($this->amount, $unit);
    }

    /**
     * Treating this quantity as a restock point, whether stock made up of these quantities
     * is down to it. Unknown means the default: when nothing is left above "low".
     *
     * A level point ("low", "out") is reached when no item is above that level; measured items count
     * as above it. An amount point ("¼ jar", "1 lb") is reached when the items measured in a
     * compatible unit add up to no more than it and nothing else usable is on hand.
     *
     * @param  iterable<self>  $onHand
     */
    public function isReachedBy(iterable $onHand): bool
    {
        $point = $this->isUnknown() ? self::level(StockLevel::Low) : $this;
        $total = 0.0;

        foreach ($onHand as $quantity) {
            if ($point->level !== null) {
                if ($quantity->level === null || $quantity->level->isAbove($point->level)) {
                    return false;
                }

                continue;
            }

            $amount = $quantity->amountIn($point->unit);

            if ($amount !== null) {
                $total += $amount;
            } elseif (! $quantity->needsRestock()) {
                return false;
            }
        }

        return $point->amount === null || $total <= $point->amount + 0.0001;
    }

    /**
     * Treating this quantity as a stock target, how much more it takes to get there from stock
     * made up of these quantities, in this one's unit ("10 lb" with "1½ lb" and "8 oz" on hand
     * is "8 lb"). Null when it isn't an amount or there's that much already.
     *
     * Only amounts that convert to its unit are taken off: there's no telling how much a "low"
     * jar or "1 bag" comes to. Containers and plain counts round up to whole ones.
     *
     * @param  iterable<self>  $onHand
     */
    public function missingFrom(iterable $onHand): ?self
    {
        if ($this->amount === null) {
            return null;
        }

        $missing = $this->amount;

        foreach ($onHand as $quantity) {
            $missing -= $quantity->amountIn($this->unit) ?? 0;
        }

        if (! $this->unit?->isMeasure()) {
            $missing = ceil($missing - 0.0001);
        }

        return $missing > 0.0001 ? self::of($missing, $this->unit) : null;
    }

    /**
     * The quantity as a person would say it. Also round-trips through parse().
     */
    public function format(): string
    {
        if ($this->level !== null) {
            return $this->level->getLabel();
        }

        if ($this->amount === null) {
            return '';
        }

        $amount = $this->unit?->isMetric()
            ? self::formatDecimal($this->amount)
            : self::formatFraction($this->amount);

        return $this->unit === null
            ? $amount
            : $amount.' '.$this->unit->labelFor($this->amount);
    }

    /**
     * @return array{amount: float|null, unit: string|null, stock_level: string|null}
     */
    public function toStorage(): array
    {
        return [
            'amount' => $this->amount,
            'unit' => $this->unit?->value,
            'stock_level' => $this->level?->value,
        ];
    }

    public function __toString(): string
    {
        return $this->format();
    }

    private static function formatDecimal(float $amount): string
    {
        return rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.');
    }

    private static function formatFraction(float $amount): string
    {
        $whole = (int) floor($amount);
        $remainder = $amount - $whole;

        if ($remainder < 0.01) {
            return (string) $whole;
        }

        if ($remainder > 0.99) {
            return (string) ($whole + 1);
        }

        foreach (self::FRACTIONS as $glyph => $value) {
            if (abs($remainder - $value) < 0.01) {
                return ($whole > 0 ? $whole : '').$glyph;
            }
        }

        return self::formatDecimal($amount);
    }
}
