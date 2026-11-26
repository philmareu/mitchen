<?php

use App\Enums\EquipmentCategory;
use App\Filament\Resources\Equipment\Pages\ManageEquipment;
use App\Models\Equipment;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Storage;

use function Pest\Livewire\livewire;

beforeEach(function () {
    actingAsCook();
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
