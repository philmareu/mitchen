<?php

use App\Actions\ReadEquipmentUpdate;
use App\Ai\Agents\EquipmentReader;
use App\Enums\EquipmentCategory;
use App\Enums\EquipmentChangeKind;
use App\Models\Equipment;
use App\Models\Recipe;
use Illuminate\Http\UploadedFile;
use Laravel\Ai\Prompts\AgentPrompt;

it('sends the note with the equipment already listed', function () {
    $mixer = Equipment::factory()->create(['name' => 'Stand mixer', 'category' => EquipmentCategory::Appliances, 'brand' => 'KitchenAid Artisan', 'size' => '5 qt']);
    $pans = Equipment::factory()->create(['name' => 'Sheet pan', 'category' => EquipmentCategory::Bakeware, 'count' => 2]);

    EquipmentReader::fake([['pieces' => [], 'not_understood' => []]]);

    app(ReadEquipmentUpdate::class)->handle('got a ricer');

    EquipmentReader::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('got a ricer')
        && $prompt->contains('Photos attached: none')
        && $prompt->contains("- equipment {$mixer->id}: Stand mixer [appliances] KitchenAid Artisan, 5 qt")
        && $prompt->contains("- equipment {$pans->id}: Sheet pan [bakeware] ×2"));
});

it('sends the photos in order', function () {
    $photos = [fakeReceiptPhoto('drawer.jpg'), fakeReceiptPhoto('mixer.jpg')];

    EquipmentReader::fake([['pieces' => [], 'not_understood' => []]]);

    app(ReadEquipmentUpdate::class)->handle('', array_map(fn (UploadedFile $photo): string => $photo->getRealPath(), $photos));

    EquipmentReader::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->attachments->count() === 2
        && $prompt->contains('(no note, just the photos)')
        && $prompt->contains('Photos attached: 2')
        && $prompt->contains('(nothing listed yet)')
        && $prompt->contains("Equipment the recipes call for that isn't listed:\n(none)"));
});

it('sends what the recipes call for that is not listed, so it is named the same', function () {
    Equipment::factory()->create(['name' => 'Stand mixer']);
    Recipe::factory()->create(['name' => 'Salsa'])->equipment()->create(['name' => 'Blender']);
    Recipe::factory()->create(['name' => 'Guacamole'])->equipment()->createMany([['name' => 'blender'], ['name' => 'Stand mixer']]);

    EquipmentReader::fake([['pieces' => [], 'not_understood' => []]]);

    app(ReadEquipmentUpdate::class)->handle('got a blender');

    EquipmentReader::assertPrompted(fn (AgentPrompt $prompt): bool => str_ends_with($prompt->prompt, "Equipment the recipes call for that isn't listed:\n- Blender (Guacamole, Salsa)"));
});

it('turns the answer into pieces checked against what is listed', function () {
    $mixer = Equipment::factory()->create(['name' => 'Stand mixer', 'category' => EquipmentCategory::Appliances, 'brand' => 'KitchenAid Artisan']);
    $photos = [fakeReceiptPhoto('drawer.jpg'), fakeReceiptPhoto('mixer.jpg')];

    EquipmentReader::fake([[
        'pieces' => [
            readerPiece(['name' => 'potato ricer', 'photos' => [1, 3, 1, 0]]),
            readerPiece(['kind' => 'existing', 'equipment_id' => $mixer->id, 'name' => 'Mixer', 'category' => 'other', 'brand' => null, 'size' => '5 qt', 'count' => 'lots', 'photos' => [2]]),
            readerPiece(['kind' => 'existing', 'equipment_id' => 999_999, 'name' => 'Ladle', 'category' => 'utensils', 'count' => 3]),
            readerPiece(['kind' => 'gone', 'equipment_id' => 999_999, 'name' => 'Blender']),
            readerPiece(['kind' => 'broken', 'name' => 'Whisk']),
            readerPiece(['name' => ' ']),
        ],
        'not_understood' => [' a jar of jam ', '', 42],
    ]]);

    $update = app(ReadEquipmentUpdate::class)->handle('', array_map(fn (UploadedFile $photo): string => $photo->getRealPath(), $photos));

    expect($update->pieces)->toHaveCount(3);

    [$ricer, $mixerPiece, $ladle] = $update->pieces;

    expect($ricer)
        ->kind->toBe(EquipmentChangeKind::Added)
        ->name->toBe('Potato ricer')
        ->category->toBe(EquipmentCategory::Tools)
        ->photos->toBe([0]);

    expect($mixerPiece)
        ->kind->toBe(EquipmentChangeKind::Existing)
        ->equipmentId->toBe($mixer->id)
        ->name->toBe('Stand mixer')
        ->category->toBe(EquipmentCategory::Appliances)
        ->brand->toBe('KitchenAid Artisan')
        ->size->toBe('5 qt')
        ->count->toBe(1)
        ->photos->toBe([1]);

    expect($ladle)
        ->kind->toBe(EquipmentChangeKind::Added)
        ->equipmentId->toBeNull()
        ->count->toBe(3);

    expect($update->notUnderstood)->toBe(['a jar of jam']);
});

it('ignores answers that are not lists', function () {
    EquipmentReader::fake([['pieces' => 'none', 'not_understood' => null]]);

    $update = app(ReadEquipmentUpdate::class)->handle('got a ricer');

    expect($update->pieces)->toBe([])
        ->and($update->notUnderstood)->toBe([]);
});
