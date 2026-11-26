<?php

use App\Models\Store;

it('looks something up in a store\'s online shop', function (string $searchUrl, string $item, string $expected) {
    $store = Store::factory()->online($searchUrl)->make();

    expect($store->searchUrl($item))->toBe($expected);
})->with([
    'one word' => ['https://shop.example.com/online/corner/search?q=milk', 'Eggs', 'https://shop.example.com/online/corner/search?q=Eggs'],
    'several words, with more after the search' => ['https://shop.example.com/search?query=milk&searchType=default', ' Chicken thighs ', 'https://shop.example.com/search?query=Chicken%20thighs&searchType=default'],
    'characters an address keeps for itself' => ['https://shop.example.com/s?k=milk', 'Half & half', 'https://shop.example.com/s?k=Half%20%26%20half'],
    'milk with a capital' => ['https://shop.example.com/search/Milk', 'Eggs', 'https://shop.example.com/search/Eggs'],
    'a shop with milk in its name' => ['https://milkmarket.example.com/find?term=milk', 'Eggs', 'https://milkmarket.example.com/find?term=Eggs'],
]);

it('has no online shop without a web address that searches for milk', function (?string $searchUrl) {
    $store = Store::factory()->make(['search_url' => $searchUrl]);

    expect($store->shopsOnline())->toBeFalse()
        ->and($store->searchUrl('Eggs'))->toBeNull();
})->with([
    'no address' => [null],
    'an address with no search for milk' => ['https://shop.example.com/home'],
    'not a web address' => ['javascript:alert("milk")'],
    'milk only in the site\'s name' => ['https://milk.example.com/search?q=eggs'],
]);

it('lists the stores with an online shop', function () {
    $online = Store::factory()->online()->create();
    Store::factory()->create();

    expect(Store::query()->online()->get()->modelKeys())->toBe([$online->id]);
});
