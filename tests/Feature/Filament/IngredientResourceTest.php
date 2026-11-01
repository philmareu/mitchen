<?php

use App\Enums\IngredientCategory;
use App\Enums\Sourcing;
use App\Enums\StockLevel;
use App\Filament\Resources\Ingredients\Pages\ManageIngredients;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use Filament\Actions\Testing\TestAction;

use function Pest\Livewire\livewire;

beforeEach(fn () => actingAsCook());

it('lists ingredients', function () {
    $ingredients = Ingredient::factory()->count(3)->create();

    $this->get('/ingredients')->assertOk();

    livewire(ManageIngredients::class)
        ->assertCanSeeTableRecords($ingredients);
});

it('creates a core ingredient', function () {
    livewire(ManageIngredients::class)
        ->callAction('create', data: [
            'name' => 'Olive oil',
            'category' => IngredientCategory::OilsAndCondiments,
            'is_core' => true,
        ])
        ->assertHasNoFormErrors();

    expect(Ingredient::sole())
        ->name->toBe('Olive oil')
        ->is_core->toBeTrue();
});

it('sets a restock point on a core ingredient', function () {
    $ingredient = Ingredient::factory()->core()->create(['name' => 'Peanut butter']);

    livewire(ManageIngredients::class)
        ->callAction(TestAction::make('edit')->table($ingredient), data: ['restock_point' => 'a quarter jar'])
        ->assertHasNoFormErrors();

    expect((string) $ingredient->fresh()->restock_point)->toBe('¼ jar');

    livewire(ManageIngredients::class)
        ->mountAction(TestAction::make('edit')->table($ingredient))
        ->assertSchemaStateSet(['restock_point' => '¼ jar']);
});

it('rejects a restock point it cannot use', function (string $point) {
    $ingredient = Ingredient::factory()->core()->create();

    livewire(ManageIngredients::class)
        ->callAction(TestAction::make('edit')->table($ingredient), data: ['restock_point' => $point])
        ->assertHasFormErrors(['restock_point']);
})->with(['full', '2 sleeves']);

it('does not allow duplicate names', function () {
    Ingredient::factory()->create(['name' => 'Olive oil']);

    livewire(ManageIngredients::class)
        ->callAction('create', data: ['name' => 'Olive oil'])
        ->assertHasFormErrors(['name' => 'unique']);
});

it('filters to ingredients that need restocking', function () {
    $needed = Ingredient::factory()->core()->has(InventoryItem::factory()->level(StockLevel::Out))->create();
    $stocked = Ingredient::factory()->core()->has(InventoryItem::factory()->quantity('1 jar'))->create();

    livewire(ManageIngredients::class)
        ->filterTable('needs_restock')
        ->assertCanSeeTableRecords([$needed])
        ->assertCanNotSeeTableRecords([$stocked]);
});

it('says what getting more takes: days for one made ahead, months for a seasonal one', function () {
    $onions = Ingredient::factory()->create(['name' => 'Pickled red onions']);
    $peaches = Ingredient::factory()->create(['name' => 'Peaches']);

    livewire(ManageIngredients::class)
        ->callAction(TestAction::make('edit')->table($peaches), data: ['sourcing' => Sourcing::Seasonal])
        ->assertHasFormErrors(['season' => 'required']);

    livewire(ManageIngredients::class)
        ->callAction(TestAction::make('edit')->table($onions), data: ['sourcing' => Sourcing::MakeAhead, 'lead_days' => 3])
        ->assertHasNoFormErrors()
        ->callAction(TestAction::make('edit')->table($peaches), data: ['sourcing' => Sourcing::Seasonal, 'season' => ['6', '7', '8']])
        ->assertHasNoFormErrors()
        ->assertTableColumnStateSet('sourcing', 'Make ahead, about 3 days', $onions)
        ->assertTableColumnStateSet('sourcing', 'In season Jun–Aug', $peaches)
        ->filterTable('sourcing', Sourcing::MakeAhead->value)
        ->assertCanSeeTableRecords([$onions])
        ->assertCanNotSeeTableRecords([$peaches]);

    expect($onions->fresh())->sourcing->toBe(Sourcing::MakeAhead)->lead_days->toBe(3)
        ->and($peaches->fresh()->season)->toBe([6, 7, 8]);
});
