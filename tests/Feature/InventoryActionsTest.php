<?php

use App\Actions\RestockIngredient;
use App\Actions\UseUpInventoryItem;
use App\Enums\StockLevel;
use App\Enums\StorageLocation;
use App\Enums\Unit;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Support\Quantity;

it('keeps a used-up core ingredient as out so it can be restocked', function () {
    $item = InventoryItem::factory()
        ->for(Ingredient::factory()->core())
        ->quantity('half a jar')
        ->create();

    $kept = app(UseUpInventoryItem::class)->handle($item);

    expect($kept)->toBeTrue()
        ->and($item->fresh()->stock_level)->toBe(StockLevel::Out)
        ->and(Ingredient::needsRestock()->pluck('id')->all())->toBe([$item->ingredient_id]);
});

it('removes a used-up one-off ingredient', function () {
    $item = InventoryItem::factory()->quantity('1 bunch')->create();

    $kept = app(UseUpInventoryItem::class)->handle($item);

    expect($kept)->toBeFalse()
        ->and($item->exists)->toBeFalse()
        ->and(Ingredient::needsRestock()->count())->toBe(0);
});

it('restocks over a low item in the same place', function () {
    $ingredient = Ingredient::factory()->core()->create();
    $low = $ingredient->inventoryItems()->create(['location' => StorageLocation::Pantry, 'quantity' => 'low']);

    $item = app(RestockIngredient::class)->handle($ingredient, Quantity::of(1, Unit::Bottle), StorageLocation::Pantry);

    expect($item->is($low))->toBeTrue()
        ->and($ingredient->inventoryItems()->count())->toBe(1)
        ->and((string) $item->fresh()->quantity)->toBe('1 bottle');
});

it('restocks somewhere new and clears out-of-stock placeholders', function () {
    $ingredient = Ingredient::factory()->core()->create();
    $ingredient->inventoryItems()->create(['location' => StorageLocation::Fridge, 'quantity' => 'out']);
    $low = $ingredient->inventoryItems()->create(['location' => StorageLocation::Counter, 'quantity' => 'low']);

    $item = app(RestockIngredient::class)->handle($ingredient, Quantity::parse('2 bottles'), StorageLocation::Pantry);

    expect($ingredient->inventoryItems()->pluck('id')->all())->toEqualCanonicalizing([$low->id, $item->id])
        ->and($item->location)->toBe(StorageLocation::Pantry)
        ->and($ingredient->fresh()->needsRestocking())->toBeFalse();
});
