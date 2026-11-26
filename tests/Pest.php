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
 * A recipe as an assistant sends it to add-recipe or update-recipe.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function sentRecipe(array $overrides = []): array
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
