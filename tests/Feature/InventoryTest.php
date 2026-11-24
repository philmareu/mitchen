<?php

use App\Enums\StockLevel;
use App\Enums\Unit;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Support\Quantity;
use App\Support\UnreadableQuantity;

it('stores a typed quantity in structured columns', function () {
    $item = InventoryItem::factory()->quantity('half a jar')->create();

    $this->assertDatabaseHas('inventory_items', [
        'id' => $item->id,
        'amount' => 0.5,
        'unit' => 'jar',
        'stock_level' => null,
    ]);

    expect($item->fresh()->quantity)->toEqual(Quantity::of(0.5, Unit::Jar))
        ->and((string) $item->fresh()->quantity)->toBe('½ jar');
});

it('swaps an amount for a stock level and back', function () {
    $item = InventoryItem::factory()->quantity('2 lb')->create();

    $item->update(['quantity' => 'running low']);

    expect($item->fresh())
        ->amount->toBeNull()
        ->unit->toBeNull()
        ->stock_level->toBe(StockLevel::Low);

    $item->update(['quantity' => '3 cans']);

    expect($item->fresh())
        ->amount->toEqual(3.0)
        ->unit->toBe(Unit::Can)
        ->stock_level->toBeNull();
});

it('refuses to save a quantity it cannot read', function () {
    InventoryItem::factory()->quantity('a smidge of things')->create();
})->throws(UnreadableQuantity::class);

it('separates items in stock from items running low', function () {
    $plenty = InventoryItem::factory()->level(StockLevel::Full)->create();
    $measured = InventoryItem::factory()->quantity('2 cans')->create();
    $unknown = InventoryItem::factory()->quantity('')->create();
    $low = InventoryItem::factory()->level(StockLevel::Low)->create();
    $out = InventoryItem::factory()->level(StockLevel::Out)->create();

    expect(InventoryItem::inStock()->pluck('id')->all())->toEqualCanonicalizing([$plenty->id, $measured->id, $unknown->id])
        ->and(InventoryItem::runningLow()->pluck('id')->all())->toEqualCanonicalizing([$low->id, $out->id]);
});

it('puts core ingredients with nothing usable on the restock list', function () {
    $missing = Ingredient::factory()->core()->create();
    $low = Ingredient::factory()->core()->has(InventoryItem::factory()->level(StockLevel::Low))->create();
    $outHere = Ingredient::factory()->core()
        ->has(InventoryItem::factory()->level(StockLevel::Out))
        ->has(InventoryItem::factory()->level(StockLevel::Low))
        ->create();
    $backup = Ingredient::factory()->core()
        ->has(InventoryItem::factory()->level(StockLevel::Low))
        ->has(InventoryItem::factory()->quantity('1 jar'))
        ->create();
    $stocked = Ingredient::factory()->core()->has(InventoryItem::factory()->quantity('2 lb'))->create();
    $oneOff = Ingredient::factory()->has(InventoryItem::factory()->level(StockLevel::Out))->create();

    $restock = Ingredient::needsRestock()->pluck('id')->all();

    expect($restock)->toEqualCanonicalizing([$missing->id, $low->id, $outHere->id]);

    foreach ([$missing, $low, $outHere, $backup, $stocked, $oneOff] as $ingredient) {
        expect($ingredient->fresh()->needsRestocking())->toBe(in_array($ingredient->id, $restock));
    }
});

it('uses each ingredient\'s restock point', function () {
    $peanutButter = Ingredient::factory()->core()->has(InventoryItem::factory()->quantity('¼ jar'))->create(['restock_point' => '¼ jar']);
    $rice = Ingredient::factory()->core()->has(InventoryItem::factory()->quantity('3 lb'))->create(['restock_point' => '1 lb']);
    $salt = Ingredient::factory()->core()->has(InventoryItem::factory()->level(StockLevel::Low))->create(['restock_point' => 'out']);
    $chicken = Ingredient::factory()->core()->has(InventoryItem::factory()->quantity('12 oz'))->create(['restock_point' => '1 lb']);
    $oneOff = Ingredient::factory()->has(InventoryItem::factory()->quantity('¼ jar'))->create(['restock_point' => '1 jar']);

    expect(Ingredient::needsRestock()->pluck('id')->all())->toEqualCanonicalizing([$peanutButter->id, $chicken->id])
        ->and($rice->fresh()->needsRestocking())->toBeFalse()
        ->and($salt->fresh()->needsRestocking())->toBeFalse()
        ->and($oneOff->fresh()->needsRestocking())->toBeFalse();
});

it('stores a restock point like a quantity', function () {
    $ingredient = Ingredient::factory()->core()->create(['restock_point' => 'a quarter of a jar']);

    $this->assertDatabaseHas('ingredients', [
        'id' => $ingredient->id,
        'restock_amount' => 0.25,
        'restock_unit' => 'jar',
        'restock_level' => null,
    ]);

    expect((string) $ingredient->fresh()->restock_point)->toBe('¼ jar');

    $ingredient->update(['restock_point' => null]);

    expect($ingredient->fresh()->restock_point->isUnknown())->toBeTrue();
});

it('stores a stock target as an amount', function () {
    $ingredient = Ingredient::factory()->core()->create(['stock_target' => '10 pounds']);

    $this->assertDatabaseHas('ingredients', [
        'id' => $ingredient->id,
        'target_amount' => 10,
        'target_unit' => 'lb',
    ]);

    expect((string) $ingredient->fresh()->stock_target)->toBe('10 lb');

    $ingredient->update(['stock_target' => 'none']);

    expect($ingredient->fresh()->stock_target->isUnknown())->toBeTrue();
});

it('refuses a stock level as a stock target', function () {
    Ingredient::factory()->core()->create(['stock_target' => 'full']);
})->throws(InvalidArgumentException::class, 'A stock target has to be an amount');

it('says how much to buy to be back at a core ingredient\'s stock target', function () {
    $beans = Ingredient::factory()->core()->has(InventoryItem::factory()->quantity('2 lb'))->create(['stock_target' => '10 lb']);
    $noTarget = Ingredient::factory()->core()->has(InventoryItem::factory()->quantity('2 lb'))->create();
    $oneOff = Ingredient::factory()->has(InventoryItem::factory()->quantity('2 lb'))->create(['stock_target' => '10 lb']);

    expect($beans->restockAmount()?->format())->toBe('8 lb')
        ->and($noTarget->restockAmount())->toBeNull()
        ->and($oneOff->restockAmount())->toBeNull();
});
