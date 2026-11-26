<?php

use App\Enums\IngredientCategory;
use App\Enums\StockLevel;
use App\Enums\StorageLocation;
use App\Filament\Resources\ShoppingListItems\Pages\ManageShoppingListItems;
use App\Filament\Resources\ShoppingListItems\ShoppingListItemResource;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\Purchase;
use App\Models\ShoppingListItem;
use App\Models\Store;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use Illuminate\Support\Arr;

use function Pest\Livewire\livewire;

beforeEach(fn () => actingAsCook());

it('lists what to buy, with a count in the navigation', function () {
    $entries = ShoppingListItem::factory()->count(2)->create();
    ShoppingListItem::factory()->bought()->create();

    $this->get('/shopping-list')->assertOk();

    livewire(ManageShoppingListItems::class)->assertCanSeeTableRecords($entries);

    expect(ShoppingListItemResource::getNavigationBadge())->toBe('2');
});

it('adds an item once, linked to its ingredient', function () {
    $eggs = Ingredient::factory()->create(['name' => 'Eggs', 'category' => IngredientCategory::DairyAndEggs]);

    livewire(ManageShoppingListItems::class)
        ->callAction('create', data: ['name' => 'eggs', 'quantity' => 'a dozen'])
        ->assertHasNoFormErrors()
        ->callAction('create', data: ['name' => 'Eggs', 'quantity' => '18'])
        ->assertHasNoFormErrors();

    expect(ShoppingListItem::sole())
        ->ingredient_id->toBe($eggs->id)
        ->quantity->toBe('18')
        ->category->toBe(IngredientCategory::DairyAndEggs);
});

it('ticks items off, then puts them away into the inventory', function () {
    $milk = Ingredient::factory()->core()->has(InventoryItem::factory()->level(StockLevel::Out)->state(['location' => StorageLocation::Fridge]))->create(['name' => 'Milk']);
    $entry = ShoppingListItem::factory()->create(['name' => 'Milk', 'quantity' => '1 gallon']);
    $towels = ShoppingListItem::factory()->create(['name' => 'Paper towels', 'category' => IngredientCategory::Other]);
    $eggs = ShoppingListItem::factory()->create(['name' => 'Eggs']);

    livewire(ManageShoppingListItems::class)
        ->assertActionHidden('putAway')
        ->call('updateTableColumnState', 'bought', (string) $entry->id, true)
        ->call('updateTableColumnState', 'bought', (string) $towels->id, true)
        ->assertActionHidden('addRestockList')
        ->assertActionVisible('putAway')
        ->mountAction('putAway')
        ->assertSchemaStateSet(fn (array $state): bool => collect($state['changes'])->map(fn (array $change): array => Arr::only($change, ['ingredient', 'quantity', 'location']))->values()->all() === [
            ['ingredient' => 'Milk', 'quantity' => '1 gallon', 'location' => StorageLocation::Fridge],
        ])
        ->callMountedAction()
        ->assertHasNoFormErrors()
        ->assertNotified(Notification::make()->title('Put away')->body('Milk: now 1 gallon (Fridge)<br>Saved to purchases<br>Off the list: Paper towels')->success())
        ->assertActionHidden('putAway')
        ->assertActionHidden('addRestockList');

    $this->assertModelMissing($entry);
    $this->assertModelMissing($towels);
    expect($eggs->fresh()->bought_at)->toBeNull()
        ->and((string) $milk->inventoryItems()->sole()->quantity)->toBe('1 gallon');
});

it('checks quantities before putting anything away', function () {
    Ingredient::factory()->create(['name' => 'Milk']);
    $entry = ShoppingListItem::factory()->bought()->create(['name' => 'Milk']);

    livewire(ManageShoppingListItems::class)
        ->mountAction('putAway')
        ->fillForm(function (array $state): array {
            $state['changes'][array_key_first($state['changes'])]['quantity'] = '2 sleeves';

            return $state;
        })
        ->callMountedAction()
        ->assertHasFormErrors();

    $this->assertModelExists($entry);
});

it('unticks an item', function () {
    $milk = ShoppingListItem::factory()->bought()->create();

    livewire(ManageShoppingListItems::class)
        ->call('updateTableColumnState', 'bought', (string) $milk->id, false);

    expect($milk->fresh()->bought_at)->toBeNull();
});

it('brings in what is on the restock list', function () {
    $milk = Ingredient::factory()->core()->has(InventoryItem::factory()->level(StockLevel::Out))->create(['name' => 'Milk']);

    livewire(ManageShoppingListItems::class)
        ->assertActionVisible('addRestockList')
        ->callAction('addRestockList')
        ->assertNotified('Added Milk')
        ->assertActionHidden('addRestockList');

    expect(ShoppingListItem::sole()->ingredient_id)->toBe($milk->id);
});

it('records where you shopped and what it cost when putting away', function () {
    Ingredient::factory()->create(['name' => 'Milk']);
    ShoppingListItem::factory()->bought()->create(['name' => 'Milk', 'quantity' => '1 gallon']);

    livewire(ManageShoppingListItems::class)
        ->mountAction('putAway')
        ->fillForm(function (array $state): array {
            $state['purchase']['store'] = 'Trader Joe\'s';
            $state['changes'][array_key_first($state['changes'])]['price'] = '4.29';

            return $state;
        })
        ->callMountedAction()
        ->assertHasNoFormErrors();

    expect(Purchase::sole())
        ->store->name->toBe('Trader Joe\'s')
        ->total->toBe(4.29);
});

it('looks up what\'s still to buy in a store\'s online shop', function () {
    $store = Store::factory()->online('https://shop.example.com/search?q=milk')->create(['name' => 'Corner Market']);
    $entry = ShoppingListItem::factory()->create(['name' => 'Chicken thighs']);
    $inCart = ShoppingListItem::factory()->bought()->create();

    $this->get('/shopping-list')->assertOk()->assertSee('Find at Corner Market');

    livewire(ManageShoppingListItems::class)
        ->assertActionHasUrl(TestAction::make("findAt{$store->id}")->table($entry), 'https://shop.example.com/search?q=Chicken%20thighs')
        ->assertActionShouldOpenUrlInNewTab(TestAction::make("findAt{$store->id}")->table($entry))
        ->assertActionHidden(TestAction::make("findAt{$store->id}")->table($inCart));
});

it('looks things up in every store that has an online shop', function () {
    $corner = Store::factory()->online('https://corner.example.com/search?q=milk')->create();
    $market = Store::factory()->online('https://market.example.com/s?k=milk')->create();
    $entry = ShoppingListItem::factory()->create(['name' => 'Eggs']);

    livewire(ManageShoppingListItems::class)
        ->assertActionHasUrl(TestAction::make("findAt{$corner->id}")->table($entry), 'https://corner.example.com/search?q=Eggs')
        ->assertActionHasUrl(TestAction::make("findAt{$market->id}")->table($entry), 'https://market.example.com/s?k=Eggs');
});

it('has nowhere to look things up for a store without an online shop', function () {
    $store = Store::factory()->create();
    $entry = ShoppingListItem::factory()->create();

    livewire(ManageShoppingListItems::class)
        ->assertActionDoesNotExist(TestAction::make("findAt{$store->id}")->table($entry));
});
