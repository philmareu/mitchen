<?php

use App\Enums\StockLevel;
use App\Enums\Unit;
use App\Support\Quantity;
use App\Support\UnreadableQuantity;

it('reads exact amounts', function (string $text, float $amount, ?Unit $unit) {
    $quantity = Quantity::parse($text);

    expect($quantity->amount)->toEqualWithDelta($amount, 0.001)
        ->and($quantity->unit)->toBe($unit)
        ->and($quantity->level)->toBeNull();
})->with([
    ['2 lb', 2, Unit::Pound],
    ['2 lbs', 2, Unit::Pound],
    ['2#', 2, Unit::Pound],
    ['500g', 500, Unit::Gram],
    ['1.5 kg', 1.5, Unit::Kilogram],
    ['.5 L', 0.5, Unit::Liter],
    ['3', 3, null],
    ['3 each', 3, null],
    ['1/2 cup', 0.5, Unit::Cup],
    ['1 1/2 cups', 1.5, Unit::Cup],
    ['1-1/2 cups', 1.5, Unit::Cup],
    ['1½ cups', 1.5, Unit::Cup],
    ['½ cup', 0.5, Unit::Cup],
    ['¾ tsp', 0.75, Unit::Teaspoon],
    ['8 fl oz', 8, Unit::FluidOunce],
    ['2 Tbsp.', 2, Unit::Tablespoon],
    ['4 tins', 4, Unit::Can],
    ['2 boxes', 2, Unit::Box],
    ['3 loaves', 3, Unit::Loaf],
]);

it('reads amounts said the way people say them', function (string $text, float $amount, ?Unit $unit) {
    $quantity = Quantity::parse($text);

    expect($quantity->amount)->toEqualWithDelta($amount, 0.001)
        ->and($quantity->unit)->toBe($unit);
})->with([
    ['half a jar', 0.5, Unit::Jar],
    ['half of a jar', 0.5, Unit::Jar],
    ['Half a jar left', 0.5, Unit::Jar],
    ['about half a bag', 0.5, Unit::Bag],
    ['a jar', 1, Unit::Jar],
    ['jar', 1, Unit::Jar],
    ['one bag', 1, Unit::Bag],
    ['a quarter of a bottle', 0.25, Unit::Bottle],
    ['three quarters of a carton', 0.75, Unit::Carton],
    ['two thirds of a box', 2 / 3, Unit::Box],
    ['a third of a bag', 1 / 3, Unit::Bag],
    ['two cans', 2, Unit::Can],
    ['a couple cans', 2, Unit::Can],
    ['a couple of cans', 2, Unit::Can],
    ['a few cloves', 3, Unit::Clove],
    ['one and a half cups', 1.5, Unit::Cup],
    ['2 and a half lbs', 2.5, Unit::Pound],
    ['1 and 1/2 cups', 1.5, Unit::Cup],
    ['a dozen', 12, null],
    ['half a dozen', 6, null],
    ['2 dozen', 24, null],
    ['2 jars of peanut butter', 2, Unit::Jar],
    ['~2 cups', 2, Unit::Cup],
    ['roughly 3 lb', 3, Unit::Pound],
]);

it('reads rough stock levels', function (string $text, StockLevel $level) {
    $quantity = Quantity::parse($text);

    expect($quantity->level)->toBe($level)
        ->and($quantity->amount)->toBeNull();
})->with([
    ['low', StockLevel::Low],
    ['  Running LOW ', StockLevel::Low],
    ['almost out', StockLevel::Low],
    ['just a little', StockLevel::Low],
    ['half', StockLevel::Half],
    ['about half', StockLevel::Half],
    ['plenty', StockLevel::Full],
    ['unopened', StockLevel::Full],
    ['out', StockLevel::Out],
    ['empty', StockLevel::Out],
    ['0', StockLevel::Out],
    ['0 cans', StockLevel::Out],
]);

it('records nothing for blank input', function (?string $text) {
    expect(Quantity::parse($text)->isUnknown())->toBeTrue();
})->with([null, '', '   ']);

it('rejects text it cannot read', function (string $text, string $message) {
    expect(fn () => Quantity::parse($text))->toThrow(UnreadableQuantity::class, $message);
})->with([
    ['lots and lots of it', 'Couldn\'t read "lots and lots of it"'],
    ['2 sleeves', '"sleeves" isn\'t a unit'],
    ['2-3 cans', '"-3 cans" isn\'t a unit'],
    ['9999999 g', 'Couldn\'t read'],
]);
