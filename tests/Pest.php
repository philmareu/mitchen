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
        'ready_on' => null,
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
 * A recipe as RecipeReader returns it.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function readerRecipe(array $overrides = []): array
{
    return [
        'name' => 'Corn tortillas',
        'source' => 'Back of the masa bag',
        'cuisine' => 'Mexican',
        'course' => 'bread',
        'protein' => null,
        'diets' => ['vegan', 'gluten_free'],
        'tags' => [],
        'servings' => 16,
        'ingredients' => [
            ['quantity' => '2 cups', 'name' => 'Masa harina', 'note' => null, 'optional' => false, 'category' => 'baking'],
            ['quantity' => '1½ cups', 'name' => 'Water', 'note' => 'warm', 'optional' => false, 'category' => 'beverages'],
            ['quantity' => '½ tsp', 'name' => 'Kosher salt', 'note' => null, 'optional' => false, 'category' => 'spices_and_herbs'],
        ],
        'substitutes' => [],
        'variants' => [],
        'equipment' => ['Tortilla press'],
        'steps' => ['Mix the masa, water and salt into a soft dough.', 'Press into rounds and cook on a hot skillet, about 1 minute a side.'],
        'notes' => null,
        'not_understood' => [],
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
