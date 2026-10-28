<?php

use App\Actions\ApplyKitchenUpdate;
use App\Enums\ChangeKind;
use App\Enums\IngredientCategory;
use App\Enums\StockLevel;
use App\Enums\StorageLocation;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\ShoppingListItem;
use App\Models\Store;
use App\Support\ProposedChange;
use App\Support\PurchaseDetails;
use App\Support\UnreadableQuantity;
use Carbon\CarbonImmutable;

function change(array $overrides = []): ProposedChange
{
    return ProposedChange::fromArray([...readerChange(), ...$overrides]);
}

it('adds a new ingredient as a one-off with what was bought', function () {
    $outcomes = app(ApplyKitchenUpdate::class)->handle([
        change(['location' => 'freezer', 'expires_on' => '2026-12-01']),
    ]);

    $ingredient = Ingredient::sole();

    expect($ingredient)
        ->name->toBe('Chicken thighs')
        ->category->toBe(IngredientCategory::MeatAndSeafood)
        ->is_core->toBeFalse();

    expect($ingredient->inventoryItems()->sole())
        ->location->toBe(StorageLocation::Freezer)
        ->and((string) $ingredient->inventoryItems()->sole()->quantity)->toBe('2 lb')
        ->and($ingredient->inventoryItems()->sole()->expires_on->toDateString())->toBe('2026-12-01');

    expect($outcomes)->toBe(['Chicken thighs: now 2 lb (Freezer)', 'Saved to purchases']);
});

it('restocks an existing ingredient, matching its name loosely', function () {
    $oil = Ingredient::factory()->core()->create(['name' => 'Olive oil']);
    $low = InventoryItem::factory()->for($oil)->level(StockLevel::Low)->create(['location' => StorageLocation::Pantry]);

    app(ApplyKitchenUpdate::class)->handle([
        change(['ingredient' => 'olive OIL', 'quantity' => '1 bottle', 'location' => 'pantry']),
    ]);

    expect(Ingredient::count())->toBe(1)
        ->and((string) $low->fresh()->quantity)->toBe('1 bottle')
        ->and(Ingredient::needsRestock()->count())->toBe(0);
});

it('adds to an item already measured in the same unit', function () {
    $beans = Ingredient::factory()->create(['name' => 'Black beans']);
    $item = InventoryItem::factory()->for($beans)->quantity('2 cans')->create(['location' => StorageLocation::Pantry]);

    app(ApplyKitchenUpdate::class)->handle([
        change(['ingredient' => 'Black beans', 'quantity' => '3 cans', 'location' => 'pantry']),
    ]);

    expect((string) $item->fresh()->quantity)->toBe('5 cans')
        ->and($beans->inventoryItems()->count())->toBe(1);
});

it('sets how much is left on the named item, or the one in the same place', function () {
    $rice = Ingredient::factory()->create(['name' => 'Jasmine rice']);
    $pantry = InventoryItem::factory()->for($rice)->quantity('2 lb')->create(['location' => StorageLocation::Pantry]);
    $counter = InventoryItem::factory()->for($rice)->quantity('1 jar')->create(['location' => StorageLocation::Counter]);

    app(ApplyKitchenUpdate::class)->handle([
        change(['kind' => 'set', 'ingredient' => 'Jasmine rice', 'inventory_item_id' => $counter->id, 'quantity' => 'low', 'location' => 'counter']),
        change(['kind' => 'set', 'ingredient' => 'Jasmine rice', 'quantity' => 'half a bag', 'location' => 'pantry']),
    ]);

    expect($counter->fresh()->stock_level)->toBe(StockLevel::Low)
        ->and((string) $pantry->fresh()->quantity)->toBe('½ bag');
});

it('moves the named item when the cook changes where it is', function () {
    $rice = Ingredient::factory()->create(['name' => 'Jasmine rice']);
    $item = InventoryItem::factory()->for($rice)->quantity('2 lb')->create(['location' => StorageLocation::Pantry]);

    app(ApplyKitchenUpdate::class)->handle([
        change(['kind' => 'set', 'ingredient' => 'Jasmine rice', 'inventory_item_id' => $item->id, 'quantity' => '1 lb', 'location' => 'counter']),
    ]);

    expect($item->fresh())->location->toBe(StorageLocation::Counter)
        ->and((string) $item->fresh()->quantity)->toBe('1 lb');
});

it('records what a photo shows in one place without touching other places', function () {
    $peanutButter = Ingredient::factory()->create(['name' => 'Peanut butter']);
    $pantry = InventoryItem::factory()->for($peanutButter)->quantity('1 jar')->create(['location' => StorageLocation::Pantry]);

    $outcomes = app(ApplyKitchenUpdate::class)->handle([
        change(['kind' => 'set', 'ingredient' => 'Peanut butter', 'quantity' => '1/4 jar', 'location' => 'fridge']),
    ]);

    expect((string) $pantry->fresh()->quantity)->toBe('1 jar')
        ->and((string) $peanutButter->inventoryItems()->where('location', StorageLocation::Fridge)->sole()->quantity)->toBe('¼ jar')
        ->and($outcomes)->toBe(['Peanut butter: set to ¼ jar (Fridge)']);
});

it('puts what is needed on the shopping list without adding ingredients', function () {
    Ingredient::factory()->create(['name' => 'Eggs', 'category' => IngredientCategory::DairyAndEggs]);

    $outcomes = app(ApplyKitchenUpdate::class)->handle([
        change(['kind' => 'need', 'ingredient' => 'Eggs', 'quantity' => 'a dozen', 'location' => null]),
        change(['kind' => 'need', 'ingredient' => 'Paper towels', 'quantity' => null, 'category' => 'other']),
    ]);

    expect(Ingredient::count())->toBe(1)
        ->and(InventoryItem::count())->toBe(0)
        ->and(ShoppingListItem::where('name', 'Eggs')->sole())->quantity->toBe('a dozen')->category->toBe(IngredientCategory::DairyAndEggs)
        ->and(ShoppingListItem::where('name', 'Paper towels')->sole()->ingredient_id)->toBeNull()
        ->and($outcomes)->toBe(['Eggs: on the shopping list', 'Paper towels: on the shopping list']);
});

it('takes the shopping list entries the cook ticked off the list', function () {
    $milk = ShoppingListItem::factory()->create(['name' => 'Milk']);
    $towels = ShoppingListItem::factory()->create(['name' => 'Paper towels']);
    $eggs = ShoppingListItem::factory()->create(['name' => 'Eggs']);

    $outcomes = app(ApplyKitchenUpdate::class)->handle([change()], [$towels->id, $milk->id]);

    $this->assertModelMissing($milk);
    $this->assertModelMissing($towels);
    expect($eggs->fresh()->bought_at)->toBeNull()
        ->and($outcomes)->toBe(['Chicken thighs: now 2 lb (Fridge)', 'Saved to purchases', 'Shopping list: got Milk and Paper towels']);
});

it('takes what it bought out of the cart so it is not put away twice', function () {
    Ingredient::factory()->create(['name' => 'Chicken thighs']);
    $chicken = ShoppingListItem::factory()->bought()->create(['name' => 'chicken thighs']);
    $towels = ShoppingListItem::factory()->bought()->create(['name' => 'Paper towels']);

    app(ApplyKitchenUpdate::class)->handle([change()]);

    $this->assertModelMissing($chicken);
    expect($towels->fresh()->bought_at)->not->toBeNull();
});

it('uses up core and one-off ingredients by their own rules', function () {
    $milk = InventoryItem::factory()->for(Ingredient::factory()->core()->state(['name' => 'Milk']))->create();
    $basil = InventoryItem::factory()->for(Ingredient::factory()->state(['name' => 'Fresh basil']))->create();
    Ingredient::factory()->create(['name' => 'Saffron']);

    $outcomes = app(ApplyKitchenUpdate::class)->handle([
        change(['kind' => 'used_up', 'ingredient' => 'Milk', 'quantity' => null]),
        change(['kind' => 'set', 'ingredient' => 'Fresh basil', 'quantity' => 'all gone']),
        change(['kind' => 'used_up', 'ingredient' => 'Saffron', 'quantity' => null]),
    ]);

    expect($milk->fresh()->stock_level)->toBe(StockLevel::Out);
    $this->assertModelMissing($basil);
    expect($outcomes)->toBe([
        'Milk: out, added to the restock list',
        'Fresh basil: used up and removed',
        'Saffron: nothing on hand to use up',
    ]);
});

it('saves nothing when any change is unreadable', function () {
    expect(fn () => app(ApplyKitchenUpdate::class)->handle([
        change(),
        change(['ingredient' => 'Crackers', 'quantity' => '2 sleeves']),
    ]))->toThrow(UnreadableQuantity::class);

    expect(Ingredient::count())->toBe(0)
        ->and(InventoryItem::count())->toBe(0);
});

it('round-trips a proposed change through form state', function () {
    $change = new ProposedChange(
        kind: ChangeKind::Set,
        ingredient: 'Eggs',
        quantity: '6',
        location: StorageLocation::Fridge,
        inventoryItemId: 4,
        category: IngredientCategory::DairyAndEggs,
        expiresOn: CarbonImmutable::parse('2026-11-14'),
        fromNote: 'six eggs left',
    );

    expect(ProposedChange::fromArray($change->toArray()))->toEqual($change);
});

it('records what was bought in the purchase history', function () {
    $this->travelTo('2026-10-28');
    Ingredient::factory()->create(['name' => 'Peanut butter']);
    Store::factory()->create(['name' => 'Trader Joe\'s']);

    $outcomes = app(ApplyKitchenUpdate::class)->handle(
        [
            change(['price' => 7.32]),
            change(['ingredient' => 'peanut butter', 'quantity' => '1 jar', 'location' => 'pantry', 'price' => '3.49', 'from_note' => 'TJ CRUNCHY PB']),
            change(['kind' => 'set', 'ingredient' => 'Peanut butter', 'quantity' => 'half a jar', 'price' => 1]),
        ],
        purchase: new PurchaseDetails('trader joe\'s', CarbonImmutable::parse('2026-10-27'), 12.05),
    );

    $purchase = Purchase::with('items')->sole();

    expect($purchase)
        ->store->name->toBe('Trader Joe\'s')
        ->purchased_on->toDateString()->toBe('2026-10-27')
        ->total->toBe(12.05)
        ->and($purchase->items->map(fn (PurchaseItem $item): array => [$item->name, (string) $item->quantity, $item->price, $item->as_written])->all())->toBe([
            ['Chicken thighs', '2 lb', 7.32, 'bought 2 lbs chicken thighs'],
            ['Peanut butter', '1 jar', 3.49, 'TJ CRUNCHY PB'],
        ])
        ->and($purchase->items->pluck('ingredient_id')->filter())->toHaveCount(2)
        ->and(Store::count())->toBe(1)
        ->and($outcomes)->toContain('Saved to purchases: Trader Joe\'s, Oct 27, $12.05');
});

it('adds a store it has not seen and totals the prices it knows', function () {
    app(ApplyKitchenUpdate::class)->handle(
        [change(['price' => 7.32]), change(['ingredient' => 'Limes', 'quantity' => '4']), change(['ingredient' => 'Milk', 'price' => 4.29])],
        purchase: new PurchaseDetails('Corner market'),
    );

    expect(Purchase::sole())
        ->store->name->toBe('Corner market')
        ->purchased_on->isToday()->toBeTrue()
        ->total->toBe(11.61);
});

it('records no purchase when nothing was bought', function () {
    InventoryItem::factory()->for(Ingredient::factory()->state(['name' => 'Rice']))->create();

    app(ApplyKitchenUpdate::class)->handle([change(['kind' => 'set', 'ingredient' => 'Rice', 'quantity' => 'low'])], purchase: new PurchaseDetails('Costco'));

    expect(Purchase::count())->toBe(0)
        ->and(Store::count())->toBe(0);
});
