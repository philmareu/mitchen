<?php

use App\Enums\IngredientCategory;
use App\Enums\StockLevel;
use App\Enums\StorageLocation;
use App\Mcp\Servers\MitchenServer;
use App\Mcp\Tools\AddToShoppingList;
use App\Mcp\Tools\GetShoppingList;
use App\Mcp\Tools\RemoveFromShoppingList;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\ShoppingListItem;
use App\Models\Store;
use App\Models\User;
use Illuminate\Testing\Fluent\AssertableJson;

beforeEach(function () {
    $this->cook = User::factory()->create();
});

it('lists what\'s to buy, with what\'s at home and the last price, and what\'s in the cart', function () {
    $eggs = Ingredient::factory()->core()->create(['name' => 'Eggs', 'category' => IngredientCategory::DairyAndEggs]);
    InventoryItem::factory()->for($eggs)->level(StockLevel::Low)->create(['location' => StorageLocation::Fridge]);
    PurchaseItem::factory()
        ->for(Purchase::factory()->for(Store::factory()->state(['name' => 'Costco']))->state(['purchased_on' => '2026-10-28']))
        ->create(['name' => 'Eggs', 'quantity' => '24', 'price' => 7.92]);
    $entry = ShoppingListItem::factory()->create(['name' => 'Eggs', 'quantity' => 'a dozen']);
    $towels = ShoppingListItem::factory()->create(['name' => 'Paper towels', 'category' => IngredientCategory::Other]);
    ShoppingListItem::factory()->bought()->create(['name' => 'Limes', 'category' => IngredientCategory::Produce]);

    MitchenServer::actingAs($this->cook)
        ->tool(GetShoppingList::class)
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->has('to_buy', 2)
            ->has('to_buy.0', fn (AssertableJson $eggs) => $eggs
                ->where('id', $entry->id)
                ->where('name', 'Eggs')
                ->where('quantity', 'a dozen')
                ->where('aisle', 'dairy_and_eggs')
                ->where('at_home.0.quantity', 'Low')
                ->where('at_home.0.location', 'fridge')
                ->where('last_paid', '$0.33 each (Costco, Oct 28)')
                ->where('find_online', []))
            ->where('to_buy.1.id', $towels->id)
            ->where('to_buy.1.at_home', [])
            ->where('to_buy.1.last_paid', null)
            ->has('in_cart', 1)
            ->where('in_cart.0.name', 'Limes'));
});

it('links what\'s to buy to the online shop of each store that has one', function () {
    Store::factory()->online('https://shop.example.com/search?q=milk')->create(['name' => 'Corner Market']);
    Store::factory()->create(['name' => 'Costco']);
    ShoppingListItem::factory()->create(['name' => 'Chicken thighs']);
    ShoppingListItem::factory()->bought()->create(['name' => 'Limes']);

    MitchenServer::actingAs($this->cook)
        ->tool(GetShoppingList::class)
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('to_buy.0.find_online', [['store' => 'Corner Market', 'url' => 'https://shop.example.com/search?q=Chicken%20thighs']])
            ->has('in_cart.0', fn (AssertableJson $limes) => $limes->where('name', 'Limes')->missing('find_online')->etc()));
});

it('adds to the shopping list once, in the catalog\'s spelling and aisle', function () {
    Ingredient::factory()->create(['name' => 'Greek yogurt', 'category' => IngredientCategory::DairyAndEggs]);
    $limes = ShoppingListItem::factory()->create(['name' => 'Limes', 'quantity' => '2']);

    MitchenServer::actingAs($this->cook)
        ->tool(AddToShoppingList::class, ['items' => [
            ['name' => 'greek yogurt', 'quantity' => '32 oz'],
            ['name' => 'limes', 'quantity' => '4'],
            ['name' => 'Paper towels', 'category' => 'other'],
        ]])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('on_the_list', fn ($entries) => collect($entries)->map(fn (array $entry): array => [$entry['name'], $entry['quantity'], $entry['aisle']])->all() === [
                ['Greek yogurt', '32 oz', 'dairy_and_eggs'],
                ['Limes', '4', $limes->category->value],
                ['Paper towels', null, 'other'],
            ]));

    expect(ShoppingListItem::query()->count())->toBe(3)
        ->and($limes->fresh()->quantity)->toBe('4');
});

it('adds the restock list to the shopping list', function () {
    $rice = Ingredient::factory()->core()->create(['name' => 'Rice']);
    InventoryItem::factory()->for($rice)->level(StockLevel::Out)->create();
    $oil = Ingredient::factory()->core()->create(['name' => 'Olive oil']);
    InventoryItem::factory()->for($oil)->create(['quantity' => 'full']);

    MitchenServer::actingAs($this->cook)
        ->tool(AddToShoppingList::class, ['restock_list' => true])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->has('on_the_list', 1)
            ->where('on_the_list.0.name', 'Rice')
            ->etc());

    expect(ShoppingListItem::query()->pluck('name')->all())->toBe(['Rice']);
});

it('adds the restock list with how much to buy to be back at the stock target', function () {
    $beans = Ingredient::factory()->core()->create(['name' => 'Pinto beans', 'restock_point' => '2 lb', 'stock_target' => '10 lb']);
    InventoryItem::factory()->for($beans)->create(['quantity' => '2 lb']);

    MitchenServer::actingAs($this->cook)
        ->tool(AddToShoppingList::class, ['restock_list' => true])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->has('on_the_list', 1)
            ->where('on_the_list.0.name', 'Pinto beans')
            ->where('on_the_list.0.quantity', '8 lb')
            ->etc());

    expect(ShoppingListItem::sole()->quantity)->toBe('8 lb');
});

it('takes entries off the shopping list', function () {
    $towels = ShoppingListItem::factory()->create(['name' => 'Paper towels']);
    $limes = ShoppingListItem::factory()->bought()->create(['name' => 'Limes']);
    $eggs = ShoppingListItem::factory()->create(['name' => 'Eggs']);

    MitchenServer::actingAs($this->cook)
        ->tool(RemoveFromShoppingList::class, ['ids' => [$towels->id, $limes->id, 999]])
        ->assertOk()
        ->assertStructuredContent(['removed' => ['Limes', 'Paper towels']]);

    $this->assertModelMissing($towels);
    $this->assertModelMissing($limes);
    $this->assertModelExists($eggs);
});

it('says so when none of the ids are on the list', function () {
    $eggs = ShoppingListItem::factory()->create(['name' => 'Eggs']);

    MitchenServer::actingAs($this->cook)
        ->tool(RemoveFromShoppingList::class, ['ids' => [999]])
        ->assertHasErrors(['None of those ids are on the shopping list.']);

    $this->assertModelExists($eggs);
});
