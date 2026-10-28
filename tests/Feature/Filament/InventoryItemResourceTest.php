<?php

use App\Enums\StockLevel;
use App\Enums\StorageLocation;
use App\Enums\Unit;
use App\Filament\Resources\InventoryItems\Pages\ManageInventoryItems;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use Filament\Actions\Testing\TestAction;

use function Pest\Livewire\livewire;

beforeEach(fn () => actingAsCook());

it('lists the inventory', function () {
    $items = InventoryItem::factory()->count(3)->create();

    $this->get('/inventory')->assertOk();

    livewire(ManageInventoryItems::class)
        ->assertCanSeeTableRecords($items);
});

it('adds an item from a typed quantity', function () {
    $ingredient = Ingredient::factory()->create(['name' => 'Peanut butter']);

    livewire(ManageInventoryItems::class)
        ->callAction('create', data: [
            'ingredient_id' => $ingredient->id,
            'location' => StorageLocation::Pantry,
            'quantity' => 'half a jar',
        ])
        ->assertHasNoFormErrors();

    expect($ingredient->inventoryItems()->sole())
        ->amount->toEqual(0.5)
        ->unit->toBe(Unit::Jar)
        ->location->toBe(StorageLocation::Pantry);
});

it('explains a quantity it cannot read', function () {
    livewire(ManageInventoryItems::class)
        ->callAction('create', data: [
            'ingredient_id' => Ingredient::factory()->create()->id,
            'location' => StorageLocation::Pantry,
            'quantity' => '2 sleeves',
        ])
        ->assertHasFormErrors(['quantity']);

    expect(InventoryItem::count())->toBe(0);
});

it('shows the current quantity when updating an item', function () {
    $item = InventoryItem::factory()->quantity('1 1/2 cups')->create();

    livewire(ManageInventoryItems::class)
        ->mountAction(TestAction::make('edit')->table($item))
        ->assertSchemaStateSet(['quantity' => '1½ cups'])
        ->setActionData(['quantity' => 'low'])
        ->callMountedAction()
        ->assertHasNoFormErrors();

    expect($item->fresh()->stock_level)->toBe(StockLevel::Low);
});

it('marks an item low', function () {
    $item = InventoryItem::factory()->quantity('2 cans')->create();

    livewire(ManageInventoryItems::class)
        ->callAction(TestAction::make('markLow')->table($item));

    expect($item->fresh()->stock_level)->toBe(StockLevel::Low);
});

it('uses up a one-off item and keeps a core one for restocking', function () {
    $oneOff = InventoryItem::factory()->create();
    $core = InventoryItem::factory()->for(Ingredient::factory()->core())->create();

    livewire(ManageInventoryItems::class)
        ->callAction(TestAction::make('useUp')->table($oneOff))
        ->callAction(TestAction::make('useUp')->table($core));

    $this->assertModelMissing($oneOff);

    expect($core->fresh()->stock_level)->toBe(StockLevel::Out);
});

it('filters to items running low', function () {
    $low = InventoryItem::factory()->level(StockLevel::Low)->create();
    $plenty = InventoryItem::factory()->level(StockLevel::Full)->create();

    livewire(ManageInventoryItems::class)
        ->filterTable('running_low')
        ->assertCanSeeTableRecords([$low])
        ->assertCanNotSeeTableRecords([$plenty]);
});
