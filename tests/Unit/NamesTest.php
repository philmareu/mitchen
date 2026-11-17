<?php

use App\Support\Names;

it('treats a name and its singular or plural as the same', function (string $name, string $other) {
    expect(Names::same($name, $other))->toBeTrue()
        ->and(Names::same($other, $name))->toBeTrue();
})->with([
    'a plain plural' => ['Lime', 'limes'],
    'the last word of a longer name' => ['White onion', 'White onions'],
    'y to ies' => ['Cherry', 'cherries'],
    'f to ves' => ['Bay leaf', 'bay leaves'],
    'case and spaces' => [' KOSHER salt ', 'Kosher salt'],
]);

it('keeps different ingredients apart', function (string $name, string $other) {
    expect(Names::same($name, $other))->toBeFalse();
})->with([
    'a kind of salt' => ['Salt', 'Kosher salt'],
    'whole and ground' => ['Black peppercorns', 'Coarse ground black pepper'],
    'a different first word' => ['Red onions', 'White onion'],
]);

it('finds the listed name, the same spelling ahead of a singular or plural', function () {
    $names = collect([4 => 'Limes', 9 => 'Lime', 12 => 'White onions']);

    expect(Names::search('lime', $names))->toBe(9)
        ->and(Names::search('LIMES', $names))->toBe(4)
        ->and(Names::search('white onion', $names))->toBe(12)
        ->and(Names::search('Salt', $names))->toBeNull();
});

it('lists names close enough to ask about, never the same one', function () {
    $catalog = collect(['Cilantro', 'Yellow onions', 'Kosher salt', 'Limes', 'Chicken stock', 'Lemons']);

    expect(Names::close('cilantor', $catalog))->toBe(['Cilantro'])
        ->and(Names::close('White onion', $catalog))->toBe(['Yellow onions'])
        ->and(Names::close('Salt', $catalog))->toBe(['Kosher salt'])
        ->and(Names::close('Lime', $catalog))->toBe([])
        ->and(Names::close('Chicken thighs', $catalog))->toBe([])
        ->and(Names::close('Lemon juice', $catalog))->toBe([]);
});
