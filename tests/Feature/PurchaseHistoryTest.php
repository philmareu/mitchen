<?php

use App\Filament\Resources\Ingredients\Pages\ManageIngredients;
use App\Filament\Resources\ShoppingListItems\Pages\ManageShoppingListItems;
use App\Models\Ingredient;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\ShoppingListItem;
use App\Models\Store;

use function Pest\Livewire\livewire;

it('prices a line per unit when it knows how much was bought', function (?string $quantity, ?float $price, ?string $expected) {
    $line = new PurchaseItem(['name' => 'Anything', 'quantity' => $quantity, 'price' => $price]);

    expect($line->formattedPrice())->toBe($expected);
})->with([
    'by weight' => ['2.13 lb', 8.5, '$3.99/lb'],
    'by the container' => ['2 cans', 3.58, '$1.79/can'],
    'a count' => ['12', 3.99, '$0.33 each'],
    'no amount' => [null, 5.49, '$5.49'],
    'a level' => ['full', 5.49, '$5.49'],
    'no price' => ['1 jar', null, null],
]);

it('shows what was last paid, where and when', function () {
    actingAsCook();
    $this->travelTo('2026-10-28');
    $eggs = Ingredient::factory()->create(['name' => 'Eggs']);
    $traderJoes = Store::factory()->create(['name' => 'Trader Joe\'s']);
    PurchaseItem::factory()->for(Purchase::factory()->for($traderJoes)->state(['purchased_on' => '2026-10-11']))->create(['name' => 'Eggs', 'quantity' => '12', 'price' => 3.99]);
    PurchaseItem::factory()->for(Purchase::factory()->for($traderJoes)->state(['purchased_on' => '2026-10-23']))->create(['name' => 'Eggs', 'quantity' => '12', 'price' => 4.29]);
    PurchaseItem::factory()->for(Purchase::factory()->state(['purchased_on' => '2026-10-25']))->create(['name' => 'Eggs', 'quantity' => '12', 'price' => null]);
    $entry = ShoppingListItem::factory()->create(['name' => 'Eggs']);

    livewire(ManageIngredients::class)
        ->assertTableColumnStateSet('last_paid', '$0.36 each', $eggs)
        ->assertTableColumnHasDescription('last_paid', 'Trader Joe\'s, Oct 23', $eggs);

    livewire(ManageShoppingListItems::class)
        ->assertTableColumnHasDescription('name', 'None at home · Last paid $0.36 each (Trader Joe\'s, Oct 23)', $entry);
});
