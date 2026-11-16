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

it('marks a ripening item ripe, and filters to what is still ripening', function () {
    $hard = InventoryItem::factory()->quantity('4')->create(['ready_on' => today()->addDays(3)]);
    $ripe = InventoryItem::factory()->quantity('2')->create();

    livewire(ManageInventoryItems::class)
        ->assertActionVisible(TestAction::make('ripeNow')->table($hard))
        ->assertActionHidden(TestAction::make('ripeNow')->table($ripe))
        ->filterTable('ripening')
        ->assertCanSeeTableRecords([$hard])
        ->assertCanNotSeeTableRecords([$ripe])
        ->callAction(TestAction::make('ripeNow')->table($hard));

    expect($hard->fresh()->ready_on)->toBeNull();
});

it('marks the selected items out: core ones stay for restocking, one-offs are removed', function () {
    $rice = InventoryItem::factory()->for(Ingredient::factory()->core())->quantity('2 lb')->create();
    $basil = InventoryItem::factory()->for(Ingredient::factory())->quantity('a bunch')->create();
    $eggs = InventoryItem::factory()->for(Ingredient::factory()->core())->quantity('a dozen')->create();

    livewire(ManageInventoryItems::class)
        ->selectTableRecords([$rice, $basil])
        ->callAction(TestAction::make('markOut')->table()->bulk())
        ->assertNotified('1 on the restock list, 1 removed from inventory');

    expect($rice->fresh()->stock_level)->toBe(StockLevel::Out)
        ->and($basil->fresh())->toBeNull()
        ->and((string) $eggs->fresh()->quantity)->toBe('12');
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
