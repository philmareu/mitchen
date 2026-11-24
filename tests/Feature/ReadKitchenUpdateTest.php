<?php

use App\Actions\ReadKitchenUpdate;
use App\Ai\Agents\KitchenUpdateReader;
use App\Ai\Agents\PhotoReader;
use App\Enums\ChangeKind;
use App\Enums\IngredientCategory;
use App\Enums\StockLevel;
use App\Enums\StorageLocation;
use App\Models\AiCall;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\ShoppingListItem;
use App\Models\Store;
use App\Support\ProposedChange;
use Laravel\Ai\Prompts\AgentPrompt;

it('sends the note with the catalog and what is on hand', function () {
    $this->travelTo('2026-10-27');
    $peanutButter = Ingredient::factory()->core()->create(['name' => 'Peanut butter', 'restock_point' => '¼ jar', 'stock_target' => '2 jars']);
    $item = InventoryItem::factory()->for($peanutButter)->quantity('half a jar')->create(['location' => StorageLocation::Pantry]);
    $avocados = InventoryItem::factory()->for(Ingredient::factory()->create(['name' => 'Avocados']))->quantity('4')->create(['location' => StorageLocation::Counter, 'ready_on' => '2026-10-30']);
    Ingredient::factory()->create(['name' => 'Saffron']);
    $towels = ShoppingListItem::factory()->create(['name' => 'Paper towels', 'quantity' => '2 rolls']);
    ShoppingListItem::factory()->bought()->create(['name' => 'Dish soap']);

    KitchenUpdateReader::fake([['changes' => [], 'not_understood' => []]]);

    app(ReadKitchenUpdate::class)->handle('out of PB');

    KitchenUpdateReader::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('Today is Tuesday, October 27, 2026')
        && $prompt->contains('out of PB')
        && $prompt->contains("- Peanut butter [core, restock at ¼ jar, stock up to 2 jars]: item {$item->id}: ½ jar in pantry")
        && $prompt->contains("- Avocados: item {$avocados->id}: 4 in counter, ripening until 2026-10-30")
        && $prompt->contains('- Saffron: nothing on hand')
        && $prompt->contains("- list {$towels->id}: Paper towels, 2 rolls")
        && ! $prompt->contains('Dish soap'));
});

it('turns the answer into changes matched to the catalog', function () {
    $milk = Ingredient::factory()->core()->create(['name' => 'Milk', 'category' => IngredientCategory::DairyAndEggs]);
    $item = InventoryItem::factory()->for($milk)->create(['location' => StorageLocation::Fridge]);

    KitchenUpdateReader::fake([[
        'changes' => [
            readerChange(['expires_on' => '2026-11-06']),
            readerChange(['kind' => 'used_up', 'ingredient' => 'milk', 'inventory_item_id' => $item->id, 'quantity' => 'none', 'location' => 'pantry', 'category' => 'beverages', 'from_note' => 'out of milk']),
        ],
        'not_understood' => [' call mom ', ''],
    ]]);

    $update = app(ReadKitchenUpdate::class)->handle('bought 2 lbs chicken thighs, use by Nov 6. out of milk. call mom');

    [$chicken, $usedMilk] = $update->changes;

    expect($chicken)
        ->kind->toBe(ChangeKind::Bought)
        ->ingredient->toBe('Chicken thighs')
        ->quantity->toBe('2 lb')
        ->location->toBe(StorageLocation::Fridge)
        ->category->toBe(IngredientCategory::MeatAndSeafood)
        ->and($chicken->expiresOn->toDateString())->toBe('2026-11-06');

    expect($usedMilk)
        ->kind->toBe(ChangeKind::UsedUp)
        ->ingredient->toBe('Milk')
        ->inventoryItemId->toBe($item->id)
        ->quantity->toBeNull()
        ->location->toBe(StorageLocation::Fridge)
        ->category->toBe(IngredientCategory::DairyAndEggs)
        ->fromNote->toBe('out of milk');

    expect($update->notUnderstood)->toBe(['call mom']);
});

it('drops item ids that do not belong to the ingredient', function () {
    $rice = Ingredient::factory()->create(['name' => 'Jasmine rice']);
    $otherItem = InventoryItem::factory()->level(StockLevel::Low)->create();

    KitchenUpdateReader::fake([['changes' => [
        readerChange(['kind' => 'set', 'ingredient' => 'Jasmine rice', 'inventory_item_id' => $otherItem->id, 'quantity' => 'half a bag', 'location' => 'pantry']),
        readerChange(['kind' => 'set', 'ingredient' => 'Jasmine rice', 'inventory_item_id' => 999_999, 'quantity' => 'low', 'location' => 'pantry']),
    ], 'not_understood' => []]]);

    $changes = app(ReadKitchenUpdate::class)->handle('half a bag of rice left')->changes;

    expect($changes)->toHaveCount(2)
        ->and($changes[0]->inventoryItemId)->toBeNull()
        ->and($changes[1]->inventoryItemId)->toBeNull()
        ->and($changes[0]->ingredient)->toBe($rice->name);
});

it('falls back to safe defaults and skips changes it cannot use', function () {
    KitchenUpdateReader::fake([['changes' => [
        readerChange(['ingredient' => 'tahini', 'location' => 'garage', 'category' => 'condiments', 'expires_on' => 'next week']),
        readerChange(['kind' => 'gave_away']),
        readerChange(['ingredient' => '  ']),
    ], 'not_understood' => []]]);

    $changes = app(ReadKitchenUpdate::class)->handle('got tahini')->changes;

    expect($changes)->toHaveCount(1)
        ->and($changes[0])
        ->ingredient->toBe('Tahini')
        ->location->toBe(StorageLocation::Pantry)
        ->category->toBe(IngredientCategory::Other)
        ->expiresOn->toBeNull();
});

it('treats malformed answers as nothing to change', function () {
    KitchenUpdateReader::fake([['changes' => 'oops', 'not_understood' => [42, ['nested']]]]);

    $update = app(ReadKitchenUpdate::class)->handle('anything');

    expect($update->changes)->toBe([])
        ->and($update->notUnderstood)->toBe([]);
});

it('sends photos to the photo reader on the default model', function () {
    config(['ai.providers.anthropic.models.text.default' => 'claude-sonnet-5-5']);
    PhotoReader::fake([['changes' => [
        readerChange(['ingredient' => 'Bananas', 'quantity' => '2.4 lb', 'location' => 'counter', 'category' => 'produce', 'from_note' => 'ORG BANANAS 2.40 lb']),
    ], 'bought_from_list' => [], 'not_understood' => ['Paper towels'], 'not_seen' => []]]);

    $update = app(ReadKitchenUpdate::class)->handle('', [fakeReceiptPhoto()->getRealPath()]);

    PhotoReader::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->attachments->count() === 1
        && $prompt->contains('(no note, just the photos)'));
    KitchenUpdateReader::assertNeverPrompted();

    expect($update->changes[0])
        ->ingredient->toBe('Bananas')
        ->quantity->toBe('2.4 lb')
        ->fromNote->toBe('ORG BANANAS 2.40 lb')
        ->and($update->notUnderstood)->toBe(['Paper towels'])
        ->and(AiCall::sole())
        ->agent->toBe(PhotoReader::class)
        ->model->toBe('claude-sonnet-5-5');
});

it('reads what is needed onto the shopping list', function () {
    $eggs = Ingredient::factory()->create(['name' => 'Eggs']);
    $item = InventoryItem::factory()->for($eggs)->create();

    KitchenUpdateReader::fake([['changes' => [
        readerChange(['kind' => 'need', 'ingredient' => 'eggs', 'inventory_item_id' => $item->id, 'quantity' => 'a dozen', 'from_note' => 'need eggs']),
        readerChange(['kind' => 'need', 'ingredient' => 'paper towels', 'quantity' => null, 'category' => 'other']),
    ], 'bought_from_list' => [], 'not_understood' => []]]);

    [$needEggs, $needTowels] = app(ReadKitchenUpdate::class)->handle('need eggs and paper towels')->changes;

    expect($needEggs)
        ->kind->toBe(ChangeKind::Need)
        ->ingredient->toBe('Eggs')
        ->inventoryItemId->toBeNull()
        ->quantity->toBe('a dozen')
        ->and($needTowels->ingredient)->toBe('Paper towels');
});

it('matches the singular or plural of a name to the catalog and the shopping list', function () {
    $limes = Ingredient::factory()->create(['name' => 'Limes']);
    $item = InventoryItem::factory()->for($limes)->create(['quantity' => '7', 'location' => StorageLocation::Counter]);
    $towels = ShoppingListItem::factory()->create(['name' => 'Paper towels']);

    KitchenUpdateReader::fake([[
        'changes' => [
            readerChange(['kind' => 'set', 'ingredient' => 'lime', 'inventory_item_id' => $item->id, 'quantity' => '3', 'location' => 'counter']),
            readerChange(['ingredient' => 'paper towel', 'quantity' => '2', 'category' => 'other']),
        ],
        'bought_from_list' => [],
        'not_understood' => [],
    ]]);

    $update = app(ReadKitchenUpdate::class)->handle('3 limes left, got paper towel');

    expect($update->changes[0])->ingredient->toBe('Limes')->inventoryItemId->toBe($item->id)
        ->and($update->boughtFromList)->toBe([$towels->id]);
});

it('ticks off shopping list entries that were bought', function () {
    $milk = Ingredient::factory()->create(['name' => 'Milk']);
    $milkEntry = ShoppingListItem::factory()->create(['name' => 'Milk']);
    $towels = ShoppingListItem::factory()->create(['name' => 'Paper towels']);
    $eggs = ShoppingListItem::factory()->create(['name' => 'Eggs']);
    $alreadyBought = ShoppingListItem::factory()->bought()->create(['name' => 'Dish soap']);

    $milk->update(['name' => 'Whole milk']);

    PhotoReader::fake([[
        'changes' => [readerChange(['ingredient' => 'Whole milk', 'quantity' => '1 gallon'])],
        'bought_from_list' => [$towels->id, (string) $alreadyBought->id, 999_999, 'eggs'],
        'not_understood' => [],
        'not_seen' => [],
    ]]);

    $update = app(ReadKitchenUpdate::class)->handle('', [fakeReceiptPhoto()->getRealPath()]);

    expect($update->boughtFromList)->toEqualCanonicalizing([$milkEntry->id, $towels->id])
        ->and($update->boughtFromList)->not->toContain($eggs->id);
});

it('keeps only the missing items a stock-take can vouch for', function () {
    $rice = InventoryItem::factory()->for(Ingredient::factory()->state(['name' => 'Rice']))->quantity('2 lb')->create();
    $flour = InventoryItem::factory()->for(Ingredient::factory()->state(['name' => 'Flour']))->quantity('1 bag')->create();
    $outAlready = InventoryItem::factory()->level(StockLevel::Out)->create();
    $lentils = InventoryItem::factory()->for(Ingredient::factory()->state(['name' => 'Lentils']))->quantity('1 jar')->create();

    PhotoReader::fake([[
        'changes' => [readerChange(['kind' => 'set', 'ingredient' => 'Flour', 'quantity' => 'half a bag', 'location' => 'pantry'])],
        'bought_from_list' => [],
        'not_understood' => [],
        'not_seen' => [$rice->id, $flour->id, $outAlready->id, 999_999, (string) $lentils->id, $rice->id],
    ]]);

    $update = app(ReadKitchenUpdate::class)->handle('pantry', [fakeReceiptPhoto('shelf.jpg')->getRealPath()]);

    expect($update->notSeen)->toBe([$rice->id, $lentils->id]);
});

it('reads prices and where and when a receipt is from', function () {
    $this->travelTo('2026-10-28');
    Store::factory()->create(['name' => 'Trader Joe\'s']);

    PhotoReader::fake([[
        'changes' => [
            readerChange(['price' => 7.32]),
            readerChange(['ingredient' => 'Limes', 'quantity' => '4', 'price' => '-1']),
            readerChange(['ingredient' => 'Basil', 'quantity' => '1 bunch', 'price' => 'two dollars']),
            readerChange(['kind' => 'set', 'ingredient' => 'Rice', 'quantity' => 'low', 'price' => 3]),
        ],
        'bought_from_list' => [],
        'purchase' => ['store' => 'TRADER JOE\'S', 'purchased_on' => '2026-10-27', 'total' => 18.444],
        'not_understood' => [],
        'not_seen' => [],
    ]]);

    $update = app(ReadKitchenUpdate::class)->handle('', [fakeReceiptPhoto()->getRealPath()]);

    PhotoReader::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains("The cook's stores:\nTrader Joe's"));

    expect(array_map(fn (ProposedChange $change): ?float => $change->price, $update->changes))->toBe([7.32, null, null, null])
        ->and($update->purchase)
        ->store->toBe('Trader Joe\'s')
        ->purchasedOn->toDateString()->toBe('2026-10-27')
        ->total->toBe(18.44);
});

it('ignores a purchase date in the future and a store that is not a name', function (mixed $purchase) {
    $this->travelTo('2026-10-28');
    KitchenUpdateReader::fake([['changes' => [readerChange()], 'bought_from_list' => [], 'purchase' => $purchase, 'not_understood' => []]]);

    $update = app(ReadKitchenUpdate::class)->handle('bought chicken');

    expect($update->purchase)
        ->store->toBeNull()
        ->purchasedOn->toBeNull()
        ->total->toBeNull();
})->with([
    'future date' => [['store' => '', 'purchased_on' => '2026-11-02', 'total' => null]],
    'junk' => [['store' => 42, 'purchased_on' => 'last week', 'total' => 'a lot']],
    'not an object' => ['Costco'],
    'missing' => [null],
]);
