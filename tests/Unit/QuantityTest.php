<?php

use App\Enums\StockLevel;
use App\Enums\Unit;
use App\Support\Quantity;

it('formats amounts for reading', function (Quantity $quantity, string $expected) {
    expect($quantity->format())->toBe($expected);
})->with([
    [fn () => Quantity::of(0.5, Unit::Jar), '½ jar'],
    [fn () => Quantity::of(1, Unit::Jar), '1 jar'],
    [fn () => Quantity::of(2, Unit::Jar), '2 jars'],
    [fn () => Quantity::of(1.5, Unit::Cup), '1½ cups'],
    [fn () => Quantity::of(1 / 3, Unit::Cup), '⅓ cup'],
    [fn () => Quantity::of(0.333, Unit::Cup), '⅓ cup'],
    [fn () => Quantity::of(2, Unit::Pound), '2 lb'],
    [fn () => Quantity::of(500, Unit::Gram), '500 g'],
    [fn () => Quantity::of(1.5, Unit::Kilogram), '1.5 kg'],
    [fn () => Quantity::of(0.75, Unit::Liter), '0.75 L'],
    [fn () => Quantity::of(2, Unit::Box), '2 boxes'],
    [fn () => Quantity::of(1.2, Unit::Pound), '1.2 lb'],
    [fn () => Quantity::of(3), '3'],
    [fn () => Quantity::level(StockLevel::Low), 'Low'],
    [fn () => Quantity::level(StockLevel::Half), 'About half'],
    [fn () => Quantity::unknown(), ''],
]);

it('round-trips through parse', function (string $text) {
    $quantity = Quantity::parse($text);

    expect(Quantity::parse($quantity->format()))->toEqual($quantity);
})->with(['half a jar', '1 1/2 cups', '2 lb', '500g', '3', 'low', 'about half', 'plenty', 'out', '2 fl oz', '3 loaves']);

it('treats a zero amount as out', function () {
    expect(Quantity::of(0, Unit::Can))->toEqual(Quantity::level(StockLevel::Out));
});

it('only asks for a restock when low or out', function () {
    expect(Quantity::level(StockLevel::Low)->needsRestock())->toBeTrue()
        ->and(Quantity::level(StockLevel::Out)->needsRestock())->toBeTrue()
        ->and(Quantity::level(StockLevel::Half)->needsRestock())->toBeFalse()
        ->and(Quantity::of(0.25, Unit::Jar)->needsRestock())->toBeFalse()
        ->and(Quantity::unknown()->needsRestock())->toBeFalse();
});

it('converts between units that measure the same thing', function () {
    expect(Unit::Pound->convert(1, Unit::Ounce))->toEqualWithDelta(16, 0.0001)
        ->and(Unit::Cup->convert(1, Unit::Tablespoon))->toEqualWithDelta(16, 0.0001)
        ->and(Unit::Gram->convert(500, Unit::Kilogram))->toEqualWithDelta(0.5, 0.0001)
        ->and(Unit::Jar->convert(2, Unit::Jar))->toBe(2.0)
        ->and(Unit::Pound->convert(1, Unit::Cup))->toBeNull()
        ->and(Unit::Jar->convert(1, Unit::Can))->toBeNull();
});

it('adds amounts in this quantity\'s unit', function (string $first, string $second, ?string $expected) {
    expect(Quantity::parse($first)->plus(Quantity::parse($second))?->format())->toBe($expected);
})->with([
    ['2 cans', '3 cans', '5 cans'],
    ['1 lb', '8 oz', '1½ lb'],
    ['1 kg', '250 g', '1.25 kg'],
    ['3', '2', '5'],
    ['2 jars', '1 can', null],
    ['3', '2 jars', null],
    ['low', '2 jars', null],
]);

it('knows when stock is down to a restock point', function (?string $point, array $onHand, bool $reached) {
    $onHand = array_map(Quantity::parse(...), $onHand);

    expect(Quantity::parse($point)->isReachedBy($onHand))->toBe($reached);
})->with([
    'nothing on hand' => ['', [], true],
    'default: low' => ['', ['low'], true],
    'default: low and out' => ['', ['low', 'out'], true],
    'default: a measured amount' => ['', ['¼ jar'], false],
    'default: about half' => ['', ['about half'], false],
    'down to the amount' => ['¼ jar', ['¼ jar'], true],
    'above the amount' => ['¼ jar', ['½ jar'], false],
    'amounts add up' => ['½ jar', ['¼ jar', '½ jar'], false],
    'amounts in other units convert' => ['1 lb', ['12 oz'], true],
    'converted amounts add up' => ['1 lb', ['12 oz', '8 oz'], false],
    'low items count for nothing' => ['2 cans', ['low', '1 can'], true],
    'a full one elsewhere is plenty' => ['1 jar', ['¼ jar', 'full'], false],
    'something on hand, amount unknown' => ['1 jar', [''], false],
    'an amount that can\'t be compared' => ['2 cans', ['1 jar'], false],
    'only when out: low' => ['out', ['low'], false],
    'only when out: out' => ['out', ['out'], true],
    'at half: about half' => ['about half', ['about half'], true],
    'at half: full' => ['about half', ['full'], false],
]);

it('works out how much it takes to get back to a stock target', function (string $target, array $onHand, ?string $missing) {
    $onHand = array_map(Quantity::parse(...), $onHand);

    expect(Quantity::parse($target)->missingFrom($onHand)?->format())->toBe($missing);
})->with([
    'nothing on hand' => ['10 lb', [], '10 lb'],
    'out' => ['10 lb', ['out'], '10 lb'],
    'less what is on hand' => ['10 lb', ['2 lb'], '8 lb'],
    'amounts in other units convert' => ['10 lb', ['1½ lb', '8 oz'], '8 lb'],
    'a part of a pound stays' => ['10 lb', ['12 oz'], '9¼ lb'],
    'a level is not taken off' => ['2 jars', ['low'], '2 jars'],
    'an amount that can\'t be compared is not taken off' => ['10 lb', ['1 bag'], '10 lb'],
    'containers round up to whole ones' => ['2 jars', ['¼ jar'], '2 jars'],
    'counts round up to whole ones' => ['12', ['4½'], '8'],
    'that much already' => ['4 cans', ['4 cans'], null],
    'more than that already' => ['1 lb', ['24 oz'], null],
    'no target' => ['', ['low'], null],
    'a level is no target' => ['full', [], null],
]);
