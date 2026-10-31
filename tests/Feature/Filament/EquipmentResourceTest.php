<?php

use App\Ai\Agents\EquipmentReader;
use App\Enums\EquipmentCategory;
use App\Filament\Resources\Equipment\Pages\ManageEquipment;
use App\Models\Equipment;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Prompts\AgentPrompt;

use function Pest\Livewire\livewire;

beforeEach(function () {
    actingAsCook();
    config(['ai.providers.anthropic.key' => 'test-key']);
    Storage::fake();
});

it('lists equipment', function () {
    $equipment = Equipment::factory()->count(3)->create();

    $this->get('/equipment')->assertOk();

    livewire(ManageEquipment::class)
        ->assertCanSeeTableRecords($equipment);
});

it('adds a piece by hand', function () {
    livewire(ManageEquipment::class)
        ->callAction('create', data: [
            'name' => 'Tortilla press',
            'category' => EquipmentCategory::Tools,
            'size' => '8 in',
        ])
        ->assertHasNoFormErrors();

    expect(Equipment::sole())
        ->name->toBe('Tortilla press')
        ->size->toBe('8 in')
        ->count->toBe(1);
});

it('deletes a piece with its photos', function () {
    Storage::put('equipment/ricer.jpg', 'ricer');
    $ricer = Equipment::factory()->create(['photos' => ['equipment/ricer.jpg']]);

    livewire(ManageEquipment::class)
        ->callAction(TestAction::make('delete')->table($ricer));

    $this->assertModelMissing($ricer);
    Storage::assertMissing('equipment/ricer.jpg');
});

it('reads photos into equipment to check, then saves each piece with its photos', function () {
    $mixer = Equipment::factory()->create(['name' => 'Stand mixer', 'category' => EquipmentCategory::Appliances]);

    EquipmentReader::fake([[
        'pieces' => [
            readerPiece(['name' => 'Ladle', 'category' => 'utensils', 'photos' => [1], 'from_note' => 'drawer, left']),
            readerPiece(['name' => 'Potato ricer', 'photos' => [1]]),
            readerPiece(['kind' => 'existing', 'equipment_id' => $mixer->id, 'name' => 'Stand mixer', 'category' => 'appliances', 'brand' => 'KitchenAid Artisan', 'photos' => [2]]),
        ],
        'not_understood' => ['black gadget at the back'],
    ]]);

    livewire(ManageEquipment::class)
        ->mountAction('quickAddEquipment')
        ->fillForm(['photos' => [fakeReceiptPhoto('drawer.jpg'), fakeReceiptPhoto('mixer.jpg')]])
        ->goToNextWizardStep()
        ->assertHasNoFormErrors()
        ->assertWizardCurrentStep(2)
        ->assertSchemaStateSet(['not_understood' => ['black gadget at the back']])
        ->assertSchemaStateSet(fn (array $state): bool => count($state['pieces']) === 3
            && $state['pieces'][0]['photos'] === ['0']
            && $state['pieces'][2]['photos'] === ['1'])
        ->callMountedAction()
        ->assertHasNoFormErrors()
        ->assertNotified(Notification::make()->title('Equipment updated')->body('Added Ladle with 1 photo<br>Added Potato ricer with 1 photo<br>Updated Stand mixer, added 1 photo')->success());

    EquipmentReader::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->attachments->count() === 2);

    expect(Equipment::count())->toBe(3)
        ->and($mixer->fresh())->brand->toBe('KitchenAid Artisan')->photos->toHaveCount(1)
        ->and(Storage::allFiles('equipment'))->toHaveCount(3);
});

it('reads a note, lets the cook correct it, then saves it', function () {
    $blender = Equipment::factory()->create(['name' => 'Blender']);

    EquipmentReader::fake([[
        'pieces' => [
            readerPiece(['name' => 'Dutch oven', 'category' => 'cookware', 'brand' => 'Lodge', 'size' => '7 qt', 'photos' => [], 'from_note' => 'a 7 qt Lodge Dutch oven']),
            readerPiece(['kind' => 'gone', 'equipment_id' => $blender->id, 'name' => 'Blender', 'category' => 'appliances', 'photos' => [], 'from_note' => 'the blender died']),
        ],
        'not_understood' => [],
    ]]);

    livewire(ManageEquipment::class)
        ->mountAction('quickAddEquipment')
        ->fillForm(['note' => 'Got a 7 qt Lodge Dutch oven. The blender died.'])
        ->goToNextWizardStep()
        ->assertWizardCurrentStep(2)
        ->fillForm(function (array $state): array {
            $state['pieces'][0]['size'] = '6 qt';

            return $state;
        })
        ->callMountedAction()
        ->assertHasNoFormErrors()
        ->assertNotified('Equipment updated');

    EquipmentReader::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('The blender died.'));

    expect(Equipment::sole())
        ->name->toBe('Dutch oven')
        ->brand->toBe('Lodge')
        ->size->toBe('6 qt')
        ->category->toBe(EquipmentCategory::Cookware);

    $this->assertModelMissing($blender);
});

it('stays on the first step when the assistant finds no equipment', function () {
    EquipmentReader::fake([['pieces' => [], 'not_understood' => ['a jar of jam']]]);

    livewire(ManageEquipment::class)
        ->mountAction('quickAddEquipment')
        ->fillForm(['note' => 'a jar of jam'])
        ->goToNextWizardStep()
        ->assertWizardCurrentStep(1)
        ->assertNotified('No equipment found');
});

it('stays on the first step when the assistant cannot be reached', function () {
    EquipmentReader::fake(fn () => throw new RuntimeException('Connection refused'));

    livewire(ManageEquipment::class)
        ->mountAction('quickAddEquipment')
        ->fillForm(['note' => 'got a ricer'])
        ->goToNextWizardStep()
        ->assertWizardCurrentStep(1)
        ->assertNotified('The assistant couldn\'t read that right now');
});

it('needs a note or a photo', function () {
    livewire(ManageEquipment::class)
        ->mountAction('quickAddEquipment')
        ->goToNextWizardStep()
        ->assertHasFormErrors(['note' => 'required_without'])
        ->assertWizardCurrentStep(1);

    EquipmentReader::assertNeverPrompted();
});

it('needs an API key', function () {
    config(['ai.providers.anthropic.key' => null]);

    livewire(ManageEquipment::class)->assertActionDisabled('quickAddEquipment');
});
