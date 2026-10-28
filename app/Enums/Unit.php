<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Units a quantity can be recorded in: weights, volumes, and the containers food actually comes in.
 *
 * A quantity with no unit is a plain count ("3" eggs).
 */
enum Unit: string implements HasLabel
{
    case Gram = 'g';
    case Kilogram = 'kg';
    case Ounce = 'oz';
    case Pound = 'lb';

    case Milliliter = 'ml';
    case Liter = 'l';
    case Teaspoon = 'tsp';
    case Tablespoon = 'tbsp';
    case FluidOunce = 'fl_oz';
    case Cup = 'cup';
    case Pint = 'pint';
    case Quart = 'quart';
    case Gallon = 'gallon';

    case Jar = 'jar';
    case Can = 'can';
    case Bottle = 'bottle';
    case Box = 'box';
    case Bag = 'bag';
    case Package = 'package';
    case Carton = 'carton';
    case Container = 'container';
    case Tub = 'tub';
    case Bunch = 'bunch';
    case Head = 'head';
    case Clove = 'clove';
    case Loaf = 'loaf';
    case Stick = 'stick';
    case Block = 'block';
    case Slice = 'slice';
    case Piece = 'piece';

    public function getLabel(): string
    {
        return $this->labelFor(1);
    }

    /**
     * The unit as it reads after an amount: "1 cup", "2 cups", "500 g".
     */
    public function labelFor(float $amount): string
    {
        if ($symbol = $this->symbol()) {
            return $symbol;
        }

        return $amount > 1 ? $this->plural() : $this->value;
    }

    /**
     * Metric amounts read better as decimals ("1.5 kg") than as fractions ("1½ kg").
     */
    public function isMetric(): bool
    {
        return in_array($this, [self::Gram, self::Kilogram, self::Milliliter, self::Liter], true);
    }

    /**
     * An amount in this unit expressed in another, or null when they measure different things
     * (weight vs volume) or either is a container ("2 jars" only compares with jars).
     */
    public function convert(float $amount, self $to): ?float
    {
        if ($this === $to) {
            return $amount;
        }

        [$dimension, $size] = $this->measure() ?? [null, null];
        [$toDimension, $toSize] = $to->measure() ?? [null, null];

        if ($dimension === null || $dimension !== $toDimension) {
            return null;
        }

        return $amount * $size / $toSize;
    }

    /**
     * Every spelling the parser accepts for this unit, lowercase.
     *
     * @return list<string>
     */
    public function aliases(): array
    {
        $aliases = match ($this) {
            self::Gram => ['g', 'gr', 'gram', 'grams', 'gramme', 'grammes'],
            self::Kilogram => ['kg', 'kgs', 'kilo', 'kilos', 'kilogram', 'kilograms'],
            self::Ounce => ['oz', 'ozs', 'ounce', 'ounces'],
            self::Pound => ['lb', 'lbs', 'pound', 'pounds', '#'],
            self::Milliliter => ['ml', 'mls', 'milliliter', 'milliliters', 'millilitre', 'millilitres'],
            self::Liter => ['l', 'liter', 'liters', 'litre', 'litres', 'ltr'],
            self::Teaspoon => ['tsp', 'tsps', 'teaspoon', 'teaspoons'],
            self::Tablespoon => ['tbsp', 'tbsps', 'tbs', 'tablespoon', 'tablespoons'],
            self::FluidOunce => ['fl oz', 'fl. oz', 'fl. oz.', 'floz', 'fluid ounce', 'fluid ounces'],
            self::Cup => ['c', 'cup', 'cups'],
            self::Pint => ['pt', 'pint', 'pints'],
            self::Quart => ['qt', 'qts', 'quart', 'quarts'],
            self::Gallon => ['gal', 'gals', 'gallon', 'gallons'],
            self::Package => ['package', 'packages', 'pkg', 'pkgs', 'pack', 'packs', 'packet', 'packets'],
            self::Can => ['can', 'cans', 'tin', 'tins'],
            self::Piece => ['piece', 'pieces', 'pc', 'pcs'],
            default => [$this->value, $this->plural()],
        };

        return $aliases;
    }

    /**
     * What the unit measures and its size in grams or milliliters (US customary volumes).
     *
     * @return array{'weight'|'volume', float}|null
     */
    private function measure(): ?array
    {
        return match ($this) {
            self::Gram => ['weight', 1.0],
            self::Kilogram => ['weight', 1_000.0],
            self::Ounce => ['weight', 28.349523125],
            self::Pound => ['weight', 453.59237],
            self::Milliliter => ['volume', 1.0],
            self::Liter => ['volume', 1_000.0],
            self::Teaspoon => ['volume', 4.92892159375],
            self::Tablespoon => ['volume', 14.78676478125],
            self::FluidOunce => ['volume', 29.5735295625],
            self::Cup => ['volume', 236.5882365],
            self::Pint => ['volume', 473.176473],
            self::Quart => ['volume', 946.352946],
            self::Gallon => ['volume', 3_785.411784],
            default => null,
        };
    }

    private function symbol(): ?string
    {
        return match ($this) {
            self::Gram, self::Kilogram, self::Ounce, self::Pound,
            self::Milliliter, self::Teaspoon, self::Tablespoon => $this->value,
            self::Liter => 'L',
            self::FluidOunce => 'fl oz',
            default => null,
        };
    }

    private function plural(): string
    {
        return match ($this) {
            self::Box => 'boxes',
            self::Bunch => 'bunches',
            self::Loaf => 'loaves',
            default => $this->value.'s',
        };
    }
}
