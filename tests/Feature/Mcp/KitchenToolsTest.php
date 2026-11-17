<?php

use App\Enums\IngredientCategory;
use App\Enums\StockLevel;
use App\Enums\StorageLocation;
use App\Mcp\Servers\MitchenServer;
use App\Mcp\Tools\ListInventory;
use App\Mcp\Tools\RestockList;
use App\Mcp\Tools\UpdateKitchen;
use App\Mcp\Tools\UseSoon;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\Purchase;
use App\Models\ShoppingListItem;
use App\Models\User;
use Illuminate\Testing\Fluent\AssertableJson;

beforeEach(function () {
    $this->cook = User::factory()->create();
});

it('lists the catalog with each item on hand', function () {
    $rice = Ingredient::factory()->core()->create(['name' => 'Jasmine rice', 'category' => IngredientCategory::GrainsAndPasta, 'restock_point' => '1 lb']);
    $item = InventoryItem::factory()->for($rice)->create(['quantity' => '2 lb', 'location' => StorageLocation::Pantry, 'expires_on' => '2027-03-01']);
    Ingredient::factory()->create(['name' => 'Saffron']);

    MitchenServer::actingAs($this->cook)
        ->tool(ListInventory::class)
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->has('ingredients', 2)
            ->has('ingredients.0', fn (AssertableJson $rice) => $rice
                ->where('name', 'Jasmine rice')
                ->where('category', 'grains_and_pasta')
                ->where('core', true)
                ->where('restock_point', '1 lb')
                ->where('needs_restock', false)
                ->where('on_hand', [['id' => $item->id, 'quantity' => '2 lb', 'location' => 'pantry', 'use_by' => '2027-03-01', 'ripening_until' => null]])
                ->etc())
            ->where('ingredients.1.name', 'Saffron')
            ->where('ingredients.1.on_hand', []));
});

it('narrows the catalog by name, place and what\'s on hand', function (array $arguments, array $expected) {
    $milk = Ingredient::factory()->create(['name' => 'Whole milk']);
    InventoryItem::factory()->for($milk)->create(['quantity' => 'half', 'location' => StorageLocation::Fridge]);
    $butter = Ingredient::factory()->create(['name' => 'Butter']);
    InventoryItem::factory()->for($butter)->create(['quantity' => '2 sticks', 'location' => StorageLocation::Fridge]);
    InventoryItem::factory()->for($butter)->create(['quantity' => '1 lb', 'location' => StorageLocation::Freezer]);
    $flour = Ingredient::factory()->create(['name' => 'Flour']);
    InventoryItem::factory()->for($flour)->level(StockLevel::Out)->create(['location' => StorageLocation::Pantry]);
    Ingredient::factory()->create(['name' => 'Buttermilk']);

    MitchenServer::actingAs($this->cook)
        ->tool(ListInventory::class, $arguments)
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('ingredients', fn ($ingredients) => collect($ingredients)
                ->mapWithKeys(fn (array $ingredient): array => [$ingredient['name'] => collect($ingredient['on_hand'])->pluck('quantity')->all()])
                ->all() === $expected));
})->with([
    'name' => [['search' => 'milk'], ['Buttermilk' => [], 'Whole milk' => ['About half']]],
    'place, showing only the items there' => [['location' => 'freezer'], ['Butter' => ['1 lb']]],
    'on hand' => [['on_hand_only' => true], ['Butter' => ['2 sticks', '1 lb'], 'Whole milk' => ['About half']]],
]);

it('lists the restock list with whether each is on the shopping list', function () {
    $eggs = Ingredient::factory()->core()->create(['name' => 'Eggs']);
    InventoryItem::factory()->for($eggs)->level(StockLevel::Low)->create();
    $oil = Ingredient::factory()->core()->create(['name' => 'Olive oil']);
    ShoppingListItem::factory()->create(['name' => 'Olive oil']);
    $salt = Ingredient::factory()->core()->create(['name' => 'Salt']);
    ShoppingListItem::factory()->bought()->create(['name' => 'Salt']);
    $rice = Ingredient::factory()->core()->create(['name' => 'Rice']);
    InventoryItem::factory()->for($rice)->create(['quantity' => 'full']);

    MitchenServer::actingAs($this->cook)
        ->tool(RestockList::class)
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->has('ingredients', 3)
            ->where('ingredients.0.name', 'Eggs')
            ->where('ingredients.0.on_hand.0.quantity', 'Low')
            ->where('ingredients.0.shopping_list', null)
            ->where('ingredients.1.name', 'Olive oil')
            ->where('ingredients.1.shopping_list', 'to_buy')
            ->where('ingredients.2.name', 'Salt')
            ->where('ingredients.2.shopping_list', 'in_cart'));
});

it('lists what to use soon, soonest first, leaving out what\'s gone', function () {
    $this->travelTo('2026-11-02');
    $spinach = Ingredient::factory()->create(['name' => 'Spinach']);
    InventoryItem::factory()->for($spinach)->create(['quantity' => '1 bag', 'expires_on' => '2026-11-05']);
    $cream = Ingredient::factory()->create(['name' => 'Cream']);
    InventoryItem::factory()->for($cream)->create(['quantity' => 'half', 'expires_on' => '2026-11-01']);
    $yogurt = Ingredient::factory()->create(['name' => 'Yogurt']);
    InventoryItem::factory()->for($yogurt)->level(StockLevel::Out)->create(['expires_on' => '2026-11-03']);
    $cheese = Ingredient::factory()->create(['name' => 'Cheddar']);
    InventoryItem::factory()->for($cheese)->create(['quantity' => '8 oz', 'expires_on' => '2026-11-20']);

    MitchenServer::actingAs($this->cook)
        ->tool(UseSoon::class)
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->has('items', 2)
            ->where('items.0.ingredient', 'Cream')
            ->where('items.0.days_left', -1)
            ->where('items.1.ingredient', 'Spinach')
            ->where('items.1.days_left', 3));

    MitchenServer::actingAs($this->cook)
        ->tool(UseSoon::class, ['days' => 30])
        ->assertStructuredContent(fn (AssertableJson $json) => $json->has('items', 3));
});

it('saves what the cook bought, used and needs', function () {
    $this->travelTo('2026-11-02');
    $milk = Ingredient::factory()->core()->create(['name' => 'Whole milk', 'category' => IngredientCategory::DairyAndEggs]);
    $carton = InventoryItem::factory()->for($milk)->level(StockLevel::Out)->create(['location' => StorageLocation::Fridge]);
    $rice = Ingredient::factory()->create(['name' => 'Jasmine rice']);
    $bag = InventoryItem::factory()->for($rice)->create(['quantity' => 'full', 'location' => StorageLocation::Counter]);
    $entry = ShoppingListItem::factory()->create(['name' => 'Whole milk']);

    MitchenServer::actingAs($this->cook)
        ->tool(UpdateKitchen::class, [
            'changes' => [
                ['kind' => 'bought', 'ingredient' => 'whole milk', 'quantity' => '1 gallon', 'price' => 4.29],
                ['kind' => 'set', 'ingredient' => 'Jasmine rice', 'quantity' => 'half a bag'],
                ['kind' => 'bought', 'ingredient' => 'chicken thighs', 'quantity' => '2 lb', 'category' => 'meat_and_seafood'],
                ['kind' => 'need', 'ingredient' => 'Paper towels'],
            ],
            'purchase' => ['store' => 'Costco', 'total' => 25.5],
        ])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('saved', [
                'Whole milk: now 1 gallon (Fridge)',
                'Jasmine rice: set to ½ bag (Counter)',
                'Chicken thighs: now 2 lb (Fridge)',
                'Paper towels: on the shopping list',
                'Saved to purchases: Costco, $25.50',
                'Shopping list: got Whole milk',
            ]));

    expect($carton->fresh()->quantity->format())->toBe('1 gallon')
        ->and($bag->fresh()->quantity->format())->toBe('½ bag');
    expect(Ingredient::query()->named('Chicken thighs')->sole())
        ->is_core->toBeFalse()
        ->inventoryItems->first()->location->toBe(StorageLocation::Fridge);
    expect(Purchase::query()->sole())->total->toEqual(25.5)->store->name->toBe('Costco');
    $this->assertModelMissing($entry);
    expect(ShoppingListItem::query()->pluck('name')->all())->toBe(['Paper towels']);
});

it('saves a singular or plural under the catalog\'s name and takes it off the list', function () {
    $limes = Ingredient::factory()->core()->create(['name' => 'Limes']);
    $item = InventoryItem::factory()->for($limes)->level(StockLevel::Out)->create(['location' => StorageLocation::Counter]);
    $towels = ShoppingListItem::factory()->create(['name' => 'Paper towels']);

    MitchenServer::actingAs($this->cook)
        ->tool(UpdateKitchen::class, [
            'changes' => [
                ['kind' => 'bought', 'ingredient' => 'lime', 'quantity' => '6'],
                ['kind' => 'bought', 'ingredient' => 'paper towel', 'quantity' => '2'],
            ],
        ])
        ->assertOk();

    expect($item->fresh()->quantity->format())->toBe('6')
        ->and(Ingredient::query()->pluck('name')->all())->toEqualCanonicalizing(['Limes', 'Paper towel']);
    $this->assertModelMissing($towels);
});

it('ignores an item id that belongs to another ingredient', function () {
    $butter = Ingredient::factory()->create(['name' => 'Butter']);
    $stick = InventoryItem::factory()->for($butter)->create(['quantity' => '2 sticks', 'location' => StorageLocation::Fridge]);
    $honey = Ingredient::factory()->create(['name' => 'Honey']);
    $jar = InventoryItem::factory()->for($honey)->create(['quantity' => 'full', 'location' => StorageLocation::Pantry]);

    MitchenServer::actingAs($this->cook)
        ->tool(UpdateKitchen::class, [
            'changes' => [['kind' => 'set', 'ingredient' => 'Butter', 'quantity' => '1 stick', 'inventory_item_id' => $jar->id]],
        ])
        ->assertOk();

    expect($stick->fresh()->quantity->format())->toBe('1 stick')
        ->and($jar->fresh()->quantity->format())->toBe('Full');
});

it('saves nothing when a quantity can\'t be read', function () {
    $flour = Ingredient::factory()->create(['name' => 'Flour']);
    $bag = InventoryItem::factory()->for($flour)->create(['quantity' => 'full']);

    MitchenServer::actingAs($this->cook)
        ->tool(UpdateKitchen::class, [
            'changes' => [
                ['kind' => 'set', 'ingredient' => 'Flour', 'quantity' => 'low'],
                ['kind' => 'bought', 'ingredient' => 'Sugar', 'quantity' => '3 glorps'],
            ],
        ])
        ->assertHasErrors(['"glorps" isn\'t a unit Mitchen knows.']);

    expect($bag->fresh()->quantity->format())->toBe('Full');
    expect(Ingredient::query()->named('Sugar')->exists())->toBeFalse();
});

it('rejects a change of an unknown kind', function () {
    MitchenServer::actingAs($this->cook)
        ->tool(UpdateKitchen::class, [
            'changes' => [['kind' => 'cooked', 'ingredient' => 'Flour']],
        ])
        ->assertHasErrors(['The selected changes.0.kind is invalid.']);
});

it('asks before adding an ingredient whose name is close to one in the catalog, but not for a need', function () {
    Ingredient::factory()->create(['name' => 'Cilantro']);
    Ingredient::factory()->create(['name' => 'Yellow onions']);

    MitchenServer::actingAs($this->cook)
        ->tool(UpdateKitchen::class, ['changes' => [
            ['kind' => 'bought', 'ingredient' => 'cilantor', 'quantity' => '1 bunch'],
            ['kind' => 'need', 'ingredient' => 'Red onion'],
        ]])
        ->assertHasErrors(['cilantor (the catalog has Cilantro). Ask the cook']);

    expect(Ingredient::count())->toBe(2)
        ->and(ShoppingListItem::count())->toBe(0);

    MitchenServer::actingAs($this->cook)
        ->tool(UpdateKitchen::class, ['changes' => [
            ['kind' => 'bought', 'ingredient' => 'Cilantro', 'quantity' => '1 bunch'],
            ['kind' => 'need', 'ingredient' => 'Red onion'],
        ]])
        ->assertOk();

    expect(Ingredient::count())->toBe(2)
        ->and(ShoppingListItem::sole()->name)->toBe('Red onion');
});
