<?php

use App\Ai\Agents\KitchenUpdateReader;
use App\Ai\Agents\PhotoReader;
use App\Enums\StockLevel;
use App\Enums\StorageLocation;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\InventoryItems\Pages\ManageInventoryItems;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\Purchase;
use App\Models\ShoppingListItem;
use App\Models\Store;
use Filament\Notifications\Notification;
use Laravel\Ai\Prompts\AgentPrompt;

use function Pest\Livewire\livewire;

beforeEach(function () {
    actingAsCook();
    config(['ai.providers.anthropic.key' => 'test-key']);
});

it('reads a note, lets the cook check it, then saves it', function () {
    $milk = InventoryItem::factory()
        ->for(Ingredient::factory()->core()->state(['name' => 'Milk']))
        ->create(['location' => StorageLocation::Fridge]);

    KitchenUpdateReader::fake([[
        'changes' => [
            readerChange(['location' => 'fridge']),
            readerChange(['kind' => 'used_up', 'ingredient' => 'Milk', 'inventory_item_id' => $milk->id, 'quantity' => null, 'from_note' => 'out of milk']),
        ],
        'not_understood' => ['call mom'],
    ]]);

    livewire(ManageInventoryItems::class)
        ->mountAction('quickUpdate')
        ->fillForm(['note' => 'Bought 2 lbs chicken thighs, out of milk, call mom'])
        ->goToNextWizardStep()
        ->assertHasNoFormErrors()
        ->assertWizardCurrentStep(2)
        ->assertSchemaStateSet(fn (array $state): bool => count($state['changes']) === 2
            && $state['changes'][0]['ingredient'] === 'Chicken thighs'
            && $state['not_understood'] === ['call mom'])
        ->fillForm(function (array $state): array {
            $state['changes'][0]['quantity'] = '3 lb';
            $state['changes'][0]['location'] = 'freezer';

            return $state;
        })
        ->callMountedAction()
        ->assertHasNoFormErrors()
        ->assertNotified('Inventory updated');

    KitchenUpdateReader::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('Bought 2 lbs chicken thighs, out of milk, call mom'));

    $chicken = Ingredient::where('name', 'Chicken thighs')->sole()->inventoryItems()->sole();

    expect((string) $chicken->quantity)->toBe('3 lb')
        ->and($chicken->location)->toBe(StorageLocation::Freezer)
        ->and($milk->fresh()->stock_level)->toBe(StockLevel::Out);
});

it('will not save a quantity it cannot read', function () {
    KitchenUpdateReader::fake([['changes' => [readerChange(['ingredient' => 'Crackers', 'quantity' => '2 sleeves'])], 'not_understood' => []]]);

    livewire(ManageInventoryItems::class)
        ->mountAction('quickUpdate')
        ->fillForm(['note' => 'got 2 sleeves of crackers'])
        ->goToNextWizardStep()
        ->callMountedAction()
        ->assertHasFormErrors(['changes.0.quantity']);

    expect(Ingredient::count())->toBe(0);
});

it('stays on the note when the assistant finds nothing to change', function () {
    KitchenUpdateReader::fake([['changes' => [], 'not_understood' => ['call mom']]]);

    livewire(ManageInventoryItems::class)
        ->mountAction('quickUpdate')
        ->fillForm(['note' => 'call mom'])
        ->goToNextWizardStep()
        ->assertWizardCurrentStep(1)
        ->assertNotified('Nothing to update');
});

it('stays on the note when the assistant cannot be reached', function () {
    KitchenUpdateReader::fake(fn () => throw new RuntimeException('Connection refused'));

    livewire(ManageInventoryItems::class)
        ->mountAction('quickUpdate')
        ->fillForm(['note' => 'out of milk'])
        ->goToNextWizardStep()
        ->assertWizardCurrentStep(1)
        ->assertNotified(Notification::make()->title('The assistant couldn\'t read that right now')->body('Check that ANTHROPIC_API_KEY is set and try again in a moment. The usage page has the error.')->danger());
});

it('reads a receipt photo into purchases to check and ticks off the list', function () {
    $yogurt = ShoppingListItem::factory()->create(['name' => 'Greek yogurt']);
    $towels = ShoppingListItem::factory()->create(['name' => 'Paper towels']);
    $eggs = ShoppingListItem::factory()->create(['name' => 'Eggs']);

    $this->travelTo('2026-10-28');
    Store::factory()->create(['name' => 'Costco']);

    PhotoReader::fake([[
        'changes' => [
            readerChange(['ingredient' => 'Greek yogurt', 'quantity' => '2 tubs', 'category' => 'dairy_and_eggs', 'from_note' => 'FAGE 2% YOGURT 2 @ 5.49', 'price' => 10.98]),
        ],
        'bought_from_list' => [$towels->id],
        'purchase' => ['store' => 'costco', 'purchased_on' => '2026-10-27', 'total' => 25.47],
        'not_understood' => ['Paper towels'],
        'not_seen' => [],
    ]]);

    livewire(ManageInventoryItems::class)
        ->mountAction('quickUpdate')
        ->fillForm(['photos' => [fakeReceiptPhoto()]])
        ->goToNextWizardStep()
        ->assertHasNoFormErrors()
        ->assertWizardCurrentStep(2)
        ->assertSchemaStateSet(['not_understood' => ['Paper towels']])
        ->assertSchemaStateSet(['purchase.store' => 'Costco', 'purchase.purchased_on' => '2026-10-27', 'purchase.total' => 25.47])
        ->assertSchemaStateSet(fn (array $state): bool => collect($state['bought_from_list'])->map(intval(...))->sort()->values()->all() === [$yogurt->id, $towels->id])
        ->callMountedAction()
        ->assertHasNoFormErrors()
        ->assertNotified(Notification::make()->title('Inventory updated')->body('Greek yogurt: now 2 tubs (Fridge)<br>Saved to purchases: Costco, Oct 27, $25.47<br>Shopping list: got Greek yogurt and Paper towels')->success());

    expect(Purchase::with('items')->sole())
        ->total->toBe(25.47)
        ->items->sole()->price->toBe(10.98);

    PhotoReader::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->attachments->count() === 1);

    $this->assertModelMissing($yogurt);
    $this->assertModelMissing($towels);
    expect((string) Ingredient::where('name', 'Greek yogurt')->sole()->inventoryItems()->sole()->quantity)->toBe('2 tubs')
        ->and($eggs->fresh()->bought_at)->toBeNull();
});

it('takes stock from a photo and uses up only what the cook ticks', function () {
    $flour = InventoryItem::factory()->for(Ingredient::factory()->state(['name' => 'Flour']))->quantity('1 bag')->create(['location' => StorageLocation::Pantry]);
    $rice = InventoryItem::factory()->for(Ingredient::factory()->core()->state(['name' => 'Rice']))->quantity('2 lb')->create(['location' => StorageLocation::Pantry]);
    $lentils = InventoryItem::factory()->for(Ingredient::factory()->state(['name' => 'Lentils']))->quantity('1 jar')->create(['location' => StorageLocation::Pantry]);

    PhotoReader::fake([[
        'changes' => [
            readerChange(['kind' => 'set', 'ingredient' => 'Flour', 'inventory_item_id' => $flour->id, 'quantity' => '1/3 bag', 'location' => 'pantry', 'from_note' => 'FLOUR jar, top shelf']),
        ],
        'bought_from_list' => [],
        'not_understood' => [],
        'not_seen' => [$rice->id, $lentils->id],
    ]]);

    livewire(ManageInventoryItems::class)
        ->mountAction('quickUpdate')
        ->fillForm(['note' => 'pantry shelf', 'photos' => [fakeReceiptPhoto('shelf.jpg')]])
        ->goToNextWizardStep()
        ->assertWizardCurrentStep(2)
        ->assertSchemaStateSet(['not_seen' => [$rice->id, $lentils->id], 'used_up_items' => []])
        ->fillForm(['used_up_items' => [(string) $rice->id]])
        ->callMountedAction()
        ->assertHasNoFormErrors();

    expect((string) $flour->fresh()->quantity)->toBe('⅓ bag')
        ->and($rice->fresh()->stock_level)->toBe(StockLevel::Out)
        ->and((string) $lentils->fresh()->quantity)->toBe('1 jar');
});

it('adds what the note says is needed to the shopping list', function () {
    KitchenUpdateReader::fake([['changes' => [
        readerChange(['kind' => 'need', 'ingredient' => 'Eggs', 'quantity' => 'a dozen', 'category' => 'dairy_and_eggs', 'from_note' => 'need eggs']),
    ], 'bought_from_list' => [], 'not_understood' => []]]);

    livewire(ManageInventoryItems::class)
        ->mountAction('quickUpdate')
        ->fillForm(['note' => 'need eggs'])
        ->goToNextWizardStep()
        ->callMountedAction()
        ->assertHasNoFormErrors()
        ->assertNotified('Inventory updated');

    expect(ShoppingListItem::sole())->name->toBe('Eggs')->quantity->toBe('a dozen')
        ->and(Ingredient::count())->toBe(0);
});

it('needs a note or a photo', function () {
    livewire(ManageInventoryItems::class)
        ->mountAction('quickUpdate')
        ->goToNextWizardStep()
        ->assertHasFormErrors(['note' => 'required_without'])
        ->assertWizardCurrentStep(1);

    KitchenUpdateReader::assertNeverPrompted();
});

it('needs an API key', function () {
    config(['ai.providers.anthropic.key' => null]);

    livewire(ManageInventoryItems::class)->assertActionDisabled('quickUpdate');
    livewire(Dashboard::class)->assertActionDisabled('quickUpdate');
});

it('is on the dashboard', function () {
    livewire(Dashboard::class)->assertActionEnabled('quickUpdate');
});
