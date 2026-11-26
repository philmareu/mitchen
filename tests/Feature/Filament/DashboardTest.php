<?php

use App\Enums\StockLevel;
use App\Enums\StorageLocation;
use App\Filament\Widgets\RestockList;
use App\Filament\Widgets\UseSoon;
use App\Filament\Widgets\WhatToCook;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\Recipe;
use App\Models\ShoppingListItem;
use Filament\Actions\Testing\TestAction;

use function Pest\Livewire\livewire;

beforeEach(fn () => actingAsCook());

it('opens on the dashboard', function () {
    $this->get('/')
        ->assertOk()
        ->assertSeeLivewire(WhatToCook::class)
        ->assertSeeLivewire(RestockList::class)
        ->assertSeeLivewire(UseSoon::class);
});

it('sends guests to the login page', function () {
    auth()->logout();

    $this->get('/')->assertRedirect('/login');
});

it('lists core ingredients to restock', function () {
    $out = Ingredient::factory()->core()->has(InventoryItem::factory()->level(StockLevel::Out))->create();
    $stocked = Ingredient::factory()->core()->has(InventoryItem::factory()->quantity('2 lb'))->create();

    livewire(RestockList::class)
        ->assertCanSeeTableRecords([$out])
        ->assertCanNotSeeTableRecords([$stocked]);
});

it('records a restock from the list', function () {
    $ingredient = Ingredient::factory()->core()->create();
    $ingredient->inventoryItems()->create(['location' => StorageLocation::Fridge, 'quantity' => 'out']);

    livewire(RestockList::class)
        ->mountAction(TestAction::make('restock')->table($ingredient))
        ->assertSchemaStateSet(['location' => StorageLocation::Fridge])
        ->setActionData(['quantity' => '1 bottle'])
        ->callMountedAction()
        ->assertHasNoFormErrors()
        ->assertCanNotSeeTableRecords([$ingredient]);

    expect((string) $ingredient->inventoryItems()->sole()->quantity)->toBe('1 bottle');
});

it('puts restock items on the shopping list', function () {
    $milk = Ingredient::factory()->core()->has(InventoryItem::factory()->level(StockLevel::Out))->create(['name' => 'Milk']);
    $rice = Ingredient::factory()->core()->create(['name' => 'Rice']);
    $flour = Ingredient::factory()->core()->create(['name' => 'Flour']);

    livewire(RestockList::class)
        ->callAction(TestAction::make('addToShoppingList')->table($milk))
        ->assertNotified('Milk is on the shopping list')
        ->assertActionHidden(TestAction::make('addToShoppingList')->table($milk))
        ->callAction(TestAction::make('addAllToShoppingList')->table())
        ->assertNotified('Added Flour and Rice to the shopping list')
        ->assertActionHidden(TestAction::make('addAllToShoppingList')->table());

    expect(ShoppingListItem::pluck('ingredient_id')->all())->toEqualCanonicalizing([$milk->id, $rice->id, $flour->id]);
});

it('shows a name with markup in it as text in its notifications', function () {
    $ingredient = Ingredient::factory()->core()->create(['name' => 'Milk <a href="https://example.com">sign in</a>']);

    livewire(RestockList::class)
        ->callAction(TestAction::make('addToShoppingList')->table($ingredient))
        ->assertNotified('Milk &lt;a href=&quot;https://example.com&quot;&gt;sign in&lt;/a&gt; is on the shopping list')
        ->callAction(TestAction::make('restock')->table($ingredient), ['quantity' => '1 gallon', 'location' => StorageLocation::Fridge->value])
        ->assertNotified('Milk &lt;a href=&quot;https://example.com&quot;&gt;sign in&lt;/a&gt; restocked');
});

it('says how much to buy to be back at the stock target and puts that on the shopping list', function () {
    $beans = Ingredient::factory()->core()->has(InventoryItem::factory()->quantity('2 lb'))->create(['name' => 'Pinto beans', 'restock_point' => '2 lb', 'stock_target' => '10 lb']);

    livewire(RestockList::class)
        ->assertTableColumnHasDescription('name', 'Restock at 2 lb · Buy 8 lb', $beans)
        ->callAction(TestAction::make('addToShoppingList')->table($beans))
        ->assertNotified('Pinto beans is on the shopping list');

    expect(ShoppingListItem::sole())->ingredient_id->toBe($beans->id)->quantity->toBe('8 lb');
});

it('takes something off the shopping list when it is restocked', function () {
    $ingredient = Ingredient::factory()->core()->create();
    $entry = ShoppingListItem::factory()->create(['name' => $ingredient->name]);
    $inTheCart = ShoppingListItem::factory()->bought()->create(['name' => $ingredient->name]);
    $other = ShoppingListItem::factory()->create();

    livewire(RestockList::class)
        ->callAction(TestAction::make('restock')->table($ingredient), data: ['quantity' => '1 bag', 'location' => StorageLocation::Pantry])
        ->assertHasNoFormErrors();

    $this->assertModelMissing($entry);
    $this->assertModelMissing($inTheCart);
    $this->assertModelExists($other);
});

it('does not offer what is already in the cart again', function () {
    $milk = Ingredient::factory()->core()->has(InventoryItem::factory()->level(StockLevel::Out))->create(['name' => 'Milk']);
    ShoppingListItem::factory()->bought()->create(['name' => 'Milk']);

    livewire(RestockList::class)
        ->assertCanSeeTableRecords([$milk])
        ->assertActionHidden(TestAction::make('addToShoppingList')->table($milk))
        ->assertActionHidden(TestAction::make('addAllToShoppingList')->table());
});

it('lists what to use soon', function () {
    $soon = InventoryItem::factory()->create(['expires_on' => today()->addDays(2)]);
    $later = InventoryItem::factory()->create(['expires_on' => today()->addDays(30)]);
    $gone = InventoryItem::factory()->level(StockLevel::Out)->create(['expires_on' => today()]);

    livewire(UseSoon::class)
        ->assertCanSeeTableRecords([$soon])
        ->assertCanNotSeeTableRecords([$later, $gone]);
});

/**
 * A recipe with these ingredients, the ones in $onHand stocked.
 *
 * @param  list<string>  $ingredients
 * @param  list<string>  $onHand
 */
function dinner(string $name, array $ingredients, array $onHand = []): Recipe
{
    $recipe = Recipe::factory()->create(['name' => $name]);

    foreach ($ingredients as $ingredient) {
        $line = $recipe->ingredients()->create(['name' => $ingredient]);

        if (in_array($ingredient, $onHand, true)) {
            InventoryItem::factory()->for($line->ingredient)->create(['quantity' => 'plenty']);
        }
    }

    return $recipe;
}

it('suggests what to cook: ready first, then a quick shop, then planning ahead', function () {
    Ingredient::factory()->makeAhead(3)->create(['name' => 'Pickled red onions']);

    $tacos = dinner('Tacos', ['Tortillas', 'Pickled red onions'], onHand: ['Tortillas']);
    $eggs = dinner('Fried eggs', ['Eggs'], onHand: ['Eggs']);
    $omelet = dinner('Omelet', ['Eggs', 'Chives']);
    $stew = dinner('Stew', ['Beef', 'Carrots', 'Potatoes', 'Onions']);
    $waffles = dinner('Waffles', ['Eggs']);
    $waffles->equipment()->create(['name' => 'Waffle iron']);
    $lastWeek = dinner('Scrambled eggs', ['Eggs']);
    $lastWeek->cookLogs()->create(['cooked_on' => today()->subWeek()]);
    $eggs->cookLogs()->create(['cooked_on' => today()->subMonth()]);

    livewire(WhatToCook::class)
        ->assertCanSeeTableRecords([$eggs, $lastWeek, $omelet, $tacos], inOrder: true)
        ->assertCanNotSeeTableRecords([$stew, $waffles])
        ->assertTableColumnHasDescription('ready', 'Pickled red onions (make ahead, about 3 days)', $tacos)
        ->assertTableActionHidden('addToShoppingList', $eggs)
        ->assertTableActionHidden('addToShoppingList', $tacos)
        ->callTableAction('addToShoppingList', $omelet)
        ->assertNotified('Added Chives to the shopping list')
        ->assertTableActionHidden('addToShoppingList', $omelet);

    expect(ShoppingListItem::sole()->name)->toBe('Chives');
});
