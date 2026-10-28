<?php

use App\Filament\Resources\Purchases\Pages\ManagePurchases;
use App\Filament\Resources\Purchases\PurchaseResource;
use App\Filament\Resources\Stores\Pages\ManageStores;
use App\Models\Ingredient;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Store;
use Filament\Actions\Testing\TestAction;

use function Pest\Livewire\livewire;

beforeEach(fn () => actingAsCook());

it('lists the purchase history, newest first', function () {
    $this->travelTo('2026-10-28');
    $older = Purchase::factory()->has(PurchaseItem::factory()->count(2), 'items')->create(['purchased_on' => '2026-10-01']);
    $newer = Purchase::factory()->has(PurchaseItem::factory(), 'items')->create(['purchased_on' => '2026-10-20']);

    $this->get('/purchases')->assertOk();

    livewire(ManagePurchases::class)
        ->assertCanSeeTableRecords([$newer, $older], inOrder: true)
        ->assertActionVisible('quickUpdate');
});

it('filters purchases by store', function () {
    $costco = Purchase::factory()->create();
    $other = Purchase::factory()->create();

    livewire(ManagePurchases::class)
        ->filterTable('store', $costco->store_id)
        ->assertCanSeeTableRecords([$costco])
        ->assertCanNotSeeTableRecords([$other]);
});

it('adds a purchase by hand, totalling the prices when no total is given', function () {
    $store = Store::factory()->create();
    $rice = Ingredient::factory()->create(['name' => 'Jasmine rice']);

    livewire(ManagePurchases::class)
        ->callAction('create', data: [
            'store_id' => $store->id,
            'purchased_on' => today()->toDateString(),
            'items' => [
                ['name' => 'jasmine rice', 'quantity' => '10 lb', 'price' => '18.99'],
                ['name' => 'Paper towels', 'quantity' => '', 'price' => '12.49'],
            ],
        ])
        ->assertHasNoFormErrors();

    $purchase = Purchase::with('items')->sole();

    expect($purchase)
        ->store_id->toBe($store->id)
        ->total->toBe(31.48)
        ->and($purchase->items->firstWhere('ingredient_id', $rice->id))
        ->name->toBe('Jasmine rice')
        ->formattedPrice()->toBe('$1.90/lb');
});

it('edits a purchase line, showing its quantity as text', function () {
    $purchase = Purchase::factory()->create(['total' => 10]);
    $line = PurchaseItem::factory()->for($purchase)->create(['name' => 'Butter', 'quantity' => '4 sticks', 'price' => 5]);

    livewire(ManagePurchases::class)
        ->mountAction(TestAction::make('edit')->table($purchase))
        ->assertSchemaStateSet(fn (array $state): bool => collect($state['items'])->first()['quantity'] === '4 sticks')
        ->fillForm(function (array $state): array {
            $state['items'][array_key_first($state['items'])]['quantity'] = '8 sticks';

            return $state;
        })
        ->callMountedAction()
        ->assertHasNoFormErrors();

    expect((string) $line->fresh()->quantity)->toBe('8 sticks');
});

it('lists stores with their trips and spending', function () {
    $store = Store::factory()->create(['name' => 'Costco']);
    Purchase::factory()->for($store)->count(2)->create(['total' => 50]);
    $unused = Store::factory()->create();

    $this->get('/stores')->assertOk();

    livewire(ManageStores::class)
        ->assertCanSeeTableRecords([$store, $unused])
        ->assertTableColumnStateSet('purchases_count', 2, $store)
        ->assertTableColumnStateSet('purchases_sum_total', 100, $store)
        ->assertActionHasUrl(TestAction::make('purchases')->table($store), PurchaseResource::getUrl(parameters: ['filters' => ['store' => ['value' => $store->id]]]));
});

it('keeps the purchases of a deleted store', function () {
    $purchase = Purchase::factory()->create();

    livewire(ManageStores::class)
        ->callAction(TestAction::make('delete')->table($purchase->store));

    expect($purchase->fresh())->store_id->toBeNull();
});
