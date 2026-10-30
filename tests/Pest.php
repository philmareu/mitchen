<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

function actingAsCook(): User
{
    $user = User::factory()->create();

    test()->actingAs($user);

    return $user;
}

/**
 * One change as KitchenUpdateReader returns it.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function readerChange(array $overrides = []): array
{
    return [
        'kind' => 'bought',
        'ingredient' => 'Chicken thighs',
        'inventory_item_id' => null,
        'quantity' => '2 lb',
        'location' => 'fridge',
        'category' => 'meat_and_seafood',
        'expires_on' => null,
        'from_note' => 'bought 2 lbs chicken thighs',
        ...$overrides,
    ];
}

/**
 * One piece as EquipmentReader returns it.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function readerPiece(array $overrides = []): array
{
    return [
        'kind' => 'added',
        'equipment_id' => null,
        'name' => 'Potato ricer',
        'category' => 'tools',
        'brand' => null,
        'size' => null,
        'count' => 1,
        'photos' => [1],
        'from_note' => 'on the counter',
        ...$overrides,
    ];
}

/**
 * A real (1×1) JPEG, so MIME detection works without GD.
 */
function fakeReceiptPhoto(string $name = 'receipt.jpg'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, base64_decode(
        '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=',
    ));
}
