<?php

use App\Models\Equipment;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake());

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
