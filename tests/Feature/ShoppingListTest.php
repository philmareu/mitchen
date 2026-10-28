<?php

use App\Actions\AddToShoppingList;
use App\Actions\PutAwayShopping;
use App\Enums\ChangeKind;
use App\Enums\IngredientCategory;
use App\Enums\StockLevel;
use App\Enums\StorageLocation;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\ShoppingListItem;
use App\Support\ProposedChange;

it('links an entry to its ingredient by name and files it in that aisle', function () {
    $eggs = Ingredient::factory()->create(['name' => 'Eggs', 'category' => IngredientCategory::DairyAndEggs]);

    $entry = app(AddToShoppingList::class)->handle(' eggs ', 'a dozen');
    $towels = app(AddToShoppingList::class)->handle('Paper towels');

    expect($entry)
        ->ingredient_id->toBe($eggs->id)
        ->name->toBe('Eggs')
        ->quantity->toBe('a dozen')
        ->category->toBe(IngredientCategory::DairyAndEggs);

    expect($towels)
        ->ingredient_id->toBeNull()
        ->category->toBe(IngredientCategory::Other);
});

it('relinks an entry when its name changes', function () {
    $milk = Ingredient::factory()->create(['name' => 'Milk']);
    $entry = ShoppingListItem::factory()->create(['name' => 'Oat milk']);

    $entry->update(['name' => 'milk']);

    expect($entry->fresh())->ingredient_id->toBe($milk->id)->name->toBe('Milk');
});

it('keeps one entry per thing still to buy', function () {
    $add = app(AddToShoppingList::class);

    $first = $add->handle('Eggs');
    $add->handle('EGGS', '18');
    $add->handle('Eggs');

    expect(ShoppingListItem::sole())->id->toBe($first->id)->quantity->toBe('18');

    $first->update(['bought_at' => now()]);
    $add->handle('Eggs');

    expect(ShoppingListItem::toBuy()->count())->toBe(1)
        ->and(ShoppingListItem::count())->toBe(2);
});

it('brings in the restock list without repeating what is already there', function () {
    $milk = Ingredient::factory()->core()->has(InventoryItem::factory()->level(StockLevel::Out))->create(['name' => 'Milk']);
    $rice = Ingredient::factory()->core()->create(['name' => 'Rice']);
    Ingredient::factory()->core()->has(InventoryItem::factory()->quantity('2 lb'))->create(['name' => 'Flour']);
    app(AddToShoppingList::class)->handle($milk, '1 gallon');

    expect(app(AddToShoppingList::class)->restockList())->toBe(['Rice'])
        ->and(app(AddToShoppingList::class)->restockList())->toBe([])
        ->and($rice->isOnShoppingList())->toBeTrue()
        ->and(ShoppingListItem::where('name', 'Milk')->sole()->quantity)->toBe('1 gallon');
});

it('keeps what is in the cart on the list until it is put away', function () {
    $milk = Ingredient::factory()->core()->has(InventoryItem::factory()->level(StockLevel::Out))->create(['name' => 'Milk']);
    ShoppingListItem::factory()->bought()->create(['name' => 'Milk']);

    expect($milk->isOnShoppingList())->toBeTrue()
        ->and(Ingredient::query()->needsRestock()->notOnShoppingList()->exists())->toBeFalse()
        ->and(app(AddToShoppingList::class)->restockList())->toBe([]);
});

it('proposes where what you got goes, keeping track of food only', function () {
    $butter = Ingredient::factory()->core()->create(['name' => 'Butter', 'category' => IngredientCategory::DairyAndEggs]);
    InventoryItem::factory()->for($butter)->quantity('2 sticks')->create(['location' => StorageLocation::Freezer]);
    InventoryItem::factory()->for($butter)->level(StockLevel::Low)->create(['location' => StorageLocation::Fridge]);
    Ingredient::factory()->create(['name' => 'Eggs', 'category' => IngredientCategory::DairyAndEggs]);

    $entries = collect([
        ['name' => 'Butter', 'quantity' => '4 sticks'],
        ['name' => 'Eggs', 'quantity' => 'for the cake'],
        ['name' => 'Lemons', 'quantity' => '3', 'category' => IngredientCategory::Produce],
        ['name' => 'Paper towels', 'category' => IngredientCategory::Other],
    ])->map(fn (array $entry): ShoppingListItem => ShoppingListItem::factory()->bought()->create($entry));

    $changes = app(PutAwayShopping::class)->propose(ShoppingListItem::query()->bought()->get());

    expect(array_keys($changes))->toEqualCanonicalizing($entries->take(3)->pluck('id')->all())
        ->and($changes[$entries[0]->id])
        ->kind->toBe(ChangeKind::Bought)
        ->quantity->toBe('4 sticks')
        ->location->toBe(StorageLocation::Fridge)
        ->and($changes[$entries[1]->id])
        ->quantity->toBeNull()
        ->fromNote->toBe('for the cake')
        ->location->toBe(StorageLocation::Fridge)
        ->and($changes[$entries[2]->id])
        ->ingredient->toBe('Lemons')
        ->quantity->toBe('3')
        ->category->toBe(IngredientCategory::Produce);
});

it('puts away what you got and takes it all off the list', function () {
    $butter = Ingredient::factory()->core()->has(InventoryItem::factory()->level(StockLevel::Out)->state(['location' => StorageLocation::Fridge]))->create(['name' => 'Butter']);
    $inTheCart = ShoppingListItem::factory()->bought()->create(['name' => 'Butter', 'quantity' => '4 sticks']);
    $towels = ShoppingListItem::factory()->bought()->create(['name' => 'Paper towels']);
    $tickedLater = ShoppingListItem::factory()->bought()->create(['name' => 'Foil']);
    $toBuy = ShoppingListItem::factory()->create(['name' => 'Eggs']);

    $outcomes = app(PutAwayShopping::class)->handle(
        [new ProposedChange(ChangeKind::Bought, 'Butter', '4 sticks', StorageLocation::Fridge)],
        [$inTheCart->id, $towels->id, $toBuy->id],
    );

    expect($outcomes)->toBe(['Butter: now 4 sticks (Fridge)', 'Saved to purchases', 'Off the list: Paper towels'])
        ->and((string) $butter->inventoryItems()->sole()->quantity)->toBe('4 sticks')
        ->and($butter->fresh('inventoryItems')->needsRestocking())->toBeFalse();

    $this->assertModelMissing($inTheCart);
    $this->assertModelMissing($towels);
    $this->assertModelExists($tickedLater);
    $this->assertModelExists($toBuy);
});
