<?php

use App\Actions\ApplyEquipmentUpdate;
use App\Enums\EquipmentCategory;
use App\Enums\EquipmentChangeKind;
use App\Models\Equipment;
use App\Support\ProposedEquipment;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake());

it('adds new equipment, each piece with its own copy of the photos it is in', function () {
    $outcomes = app(ApplyEquipmentUpdate::class)->handle([
        new ProposedEquipment(EquipmentChangeKind::Added, 'Ladle', EquipmentCategory::Utensils, photos: [0]),
        new ProposedEquipment(EquipmentChangeKind::Added, 'Whisk', EquipmentCategory::Utensils, photos: [0, 1]),
        new ProposedEquipment(EquipmentChangeKind::Added, 'Sheet pan', EquipmentCategory::Bakeware, size: 'Half sheet', count: 2),
    ], [fakeReceiptPhoto('drawer.jpg'), fakeReceiptPhoto('whisk.jpg')]);

    expect($outcomes)->toBe(['Added Ladle with 1 photo', 'Added Whisk with 2 photos', 'Added Sheet pan']);

    $ladle = Equipment::where('name', 'Ladle')->sole();
    $whisk = Equipment::where('name', 'Whisk')->sole();

    expect($ladle->photos)->toHaveCount(1)
        ->and($whisk->photos)->toHaveCount(2)
        ->and($ladle->photos[0])->toStartWith('equipment/')->not->toBeIn($whisk->photos)
        ->and(Storage::allFiles('equipment'))->toHaveCount(3)
        ->and(Equipment::where('name', 'Sheet pan')->sole())
        ->count->toBe(2)
        ->size->toBe('Half sheet')
        ->photos->toBe([]);
});

it('updates equipment that is already listed and adds the new photos', function () {
    Storage::put('equipment/old.jpg', 'old');
    $mixer = Equipment::factory()->create(['name' => 'Stand mixer', 'photos' => ['equipment/old.jpg']]);

    $outcomes = app(ApplyEquipmentUpdate::class)->handle([
        new ProposedEquipment(EquipmentChangeKind::Existing, 'Stand mixer', EquipmentCategory::Appliances, brand: 'KitchenAid Artisan', size: '5 qt', equipmentId: $mixer->id, photos: [0]),
    ], [fakeReceiptPhoto()]);

    expect($outcomes)->toBe(['Updated Stand mixer, added 1 photo']);

    $mixer->refresh();

    expect($mixer)
        ->brand->toBe('KitchenAid Artisan')
        ->size->toBe('5 qt')
        ->and($mixer->photos)->toHaveCount(2)
        ->and($mixer->photos[0])->toBe('equipment/old.jpg');

    Storage::assertExists($mixer->photos);
});

it('finds a listed piece by name when the cook says they already have it', function () {
    $mixer = Equipment::factory()->create(['name' => 'Stand mixer']);

    app(ApplyEquipmentUpdate::class)->handle([
        new ProposedEquipment(EquipmentChangeKind::Existing, 'stand mixer', EquipmentCategory::Appliances, size: '5 qt'),
    ]);

    expect(Equipment::sole())->id->toBe($mixer->id)->size->toBe('5 qt');
});

it('removes equipment that is gone, with its photos', function () {
    Storage::put('equipment/blender.jpg', 'blender');
    $blender = Equipment::factory()->create(['name' => 'Blender', 'photos' => ['equipment/blender.jpg']]);

    $outcomes = app(ApplyEquipmentUpdate::class)->handle([
        new ProposedEquipment(EquipmentChangeKind::Gone, 'Blender', equipmentId: $blender->id, photos: [0]),
        new ProposedEquipment(EquipmentChangeKind::Gone, 'Waffle iron'),
    ], [fakeReceiptPhoto()]);

    expect($outcomes)->toBe(['Removed Blender', 'Waffle iron: not listed, nothing removed']);

    $this->assertModelMissing($blender);
    expect(Storage::allFiles())->toBe([]);
});

it('keeps none of the photos when saving fails', function () {
    Equipment::saving(fn (Equipment $equipment) => $equipment->name === 'Whisk' ? throw new RuntimeException('Disk full') : null);

    expect(fn () => app(ApplyEquipmentUpdate::class)->handle([
        new ProposedEquipment(EquipmentChangeKind::Added, 'Ladle', photos: [0]),
        new ProposedEquipment(EquipmentChangeKind::Added, 'Whisk', photos: [0]),
    ], [fakeReceiptPhoto()]))->toThrow(RuntimeException::class, 'Disk full');

    expect(Equipment::count())->toBe(0)
        ->and(Storage::allFiles())->toBe([]);
});

it('deletes photos taken off a piece, and the rest with the piece', function () {
    Storage::put('equipment/a.jpg', 'a');
    Storage::put('equipment/b.jpg', 'b');
    $skillet = Equipment::factory()->create(['photos' => ['equipment/a.jpg', 'equipment/b.jpg']]);

    $skillet->update(['photos' => ['equipment/b.jpg']]);

    Storage::assertMissing('equipment/a.jpg');
    Storage::assertExists('equipment/b.jpg');

    $skillet->delete();

    Storage::assertMissing('equipment/b.jpg');
});
