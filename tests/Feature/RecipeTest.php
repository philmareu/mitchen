<?php

use App\Actions\AddRecipe;
use App\Actions\AddToShoppingList;
use App\Enums\Availability;
use App\Enums\IngredientCategory;
use App\Enums\StorageLocation;
use App\Models\Equipment;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\Recipe;
use App\Models\RecipeEquipment;
use App\Models\RecipeIngredient;
use App\Models\ShoppingListItem;
use App\Support\RecipeDraft;
use App\Support\RecipeLine;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake());

/**
 * Some of an ingredient on hand.
 */
function stock(string $ingredient, string $quantity, StorageLocation $location = StorageLocation::Pantry): Ingredient
{
    $ingredient = Ingredient::query()->named($ingredient)->first() ?? Ingredient::factory()->create(['name' => $ingredient]);

    InventoryItem::factory()->for($ingredient)->create(['quantity' => $quantity, 'location' => $location]);

    return $ingredient;
}

it('links ingredient lines to the catalog, adding new ones as one-offs', function () {
    $salt = Ingredient::factory()->core()->create(['name' => 'Kosher salt']);
    $recipe = Recipe::factory()->create();

    $line = $recipe->ingredients()->create(['name' => 'kosher salt', 'quantity' => '½ tsp']);
    $masa = $recipe->ingredients()->create(['name' => 'masa harina', 'quantity' => '2 cups']);

    expect($line)->ingredient_id->toBe($salt->id)->name->toBe('Kosher salt')
        ->and($masa->ingredient)->name->toBe('Masa harina')->is_core->toBeFalse()
        ->and($masa->name)->toBe('Masa harina');

    $line->update(['name' => 'Masa harina']);

    expect($line->ingredient_id)->toBe($masa->ingredient_id)
        ->and(Ingredient::count())->toBe(2);
});

it('links equipment the kitchen has, and links up when it gets one', function () {
    $skillet = Equipment::factory()->create(['name' => 'Cast iron skillet']);
    $recipe = Recipe::factory()->create();

    $pan = $recipe->equipment()->create(['name' => 'cast iron skillet']);
    $press = $recipe->equipment()->create(['name' => 'Tortilla press']);

    expect($pan)->equipment_id->toBe($skillet->id)->name->toBe('Cast iron skillet')->isInKitchen()->toBeTrue()
        ->and($press->isInKitchen())->toBeFalse();

    $tortillaPress = Equipment::factory()->create(['name' => 'tortilla press']);

    expect($press->fresh())->equipment_id->toBe($tortillaPress->id)->name->toBe('tortilla press');

    $skillet->delete();

    expect($pan->fresh()->isInKitchen())->toBeFalse();
});

it('says whether each ingredient is on hand, running low or out, and where it is', function () {
    stock('Eggs', 'a dozen', StorageLocation::Fridge);
    stock('Eggs', 'out', StorageLocation::Counter);
    stock('Olive oil', 'low');
    stock('Soy sauce', 'out');
    stock('Garlic', '');

    $recipe = Recipe::factory()->create();

    foreach (['Eggs', 'Olive oil', 'Soy sauce', 'Garlic', 'Saffron'] as $name) {
        $recipe->ingredients()->create(['name' => $name]);
    }

    [$eggs, $oil, $soy, $garlic, $saffron] = $recipe->ingredients;

    expect($eggs->availability())->toBe(Availability::OnHand)
        ->and($eggs->whereItIs())->toBe('12 · Fridge')
        ->and($oil->availability())->toBe(Availability::Low)
        ->and($oil->whereItIs())->toBe('Low · Pantry')
        ->and($soy->availability())->toBe(Availability::Out)
        ->and($soy->whereItIs())->toBeNull()
        ->and($garlic->availability())->toBe(Availability::OnHand)
        ->and($garlic->whereItIs())->toBe('Some · Pantry')
        ->and($saffron->availability())->toBe(Availability::Out);
});

it('counts water and ice as always on hand', function () {
    $recipe = Recipe::factory()->create();
    $water = $recipe->ingredients()->create(['name' => 'water', 'quantity' => '1½ cups']);
    $ice = $recipe->ingredients()->create(['name' => 'Ice']);
    $milk = $recipe->ingredients()->create(['name' => 'Milk']);

    expect($water->ingredient->always_on_hand)->toBeTrue()
        ->and($water->availability())->toBe(Availability::OnHand)
        ->and($ice->availability())->toBe(Availability::OnHand)
        ->and($milk->ingredient->always_on_hand)->toBeFalse()
        ->and($milk->availability())->toBe(Availability::Out);

    $milk->ingredient->update(['always_on_hand' => true]);

    expect($recipe->fresh()->missing())->toBe([]);
});

it('knows what is missing and which recipes are ready to cook', function () {
    stock('Eggs', 'a dozen');
    stock('Olive oil', 'low');
    Equipment::factory()->create(['name' => 'Cast iron skillet']);

    $ready = Recipe::factory()->create(['name' => 'Fried eggs']);
    $ready->ingredients()->create(['name' => 'Eggs']);
    $ready->ingredients()->create(['name' => 'Olive oil']);
    $ready->ingredients()->create(['name' => 'Water']);
    RecipeIngredient::factory()->optional()->for($ready)->create(['name' => 'Chives']);
    $ready->equipment()->create(['name' => 'Cast iron skillet']);

    $needsIngredient = Recipe::factory()->create(['name' => 'Soy eggs']);
    $needsIngredient->ingredients()->create(['name' => 'Eggs']);
    $needsIngredient->ingredients()->create(['name' => 'Soy sauce']);

    $needsEquipment = Recipe::factory()->create(['name' => 'Tortillas']);
    $needsEquipment->equipment()->create(['name' => 'Tortilla press']);

    expect($ready->missing())->toBe([])
        ->and($needsIngredient->missing())->toBe(['Soy sauce'])
        ->and($needsEquipment->missing())->toBe(['Tortilla press'])
        ->and(Recipe::query()->readyToCook()->pluck('name')->all())->toBe(['Fried eggs']);
});

it('scales the amounts it can read and leaves the rest as written', function (?string $quantity, float $scale, ?string $expected) {
    $line = new RecipeIngredient(['quantity' => $quantity]);

    expect($line->quantityFor($scale))->toBe($expected);
})->with([
    'halved' => ['1½ cups', 0.5, '¾ cup'],
    'doubled shorthand' => ['2 c.', 2, '4 cups'],
    'a count' => ['2', 1.5, '3'],
    'unscaled stays as written' => ['2 c.', 1, '2 c.'],
    'a pinch' => ['a pinch', 2, 'a pinch'],
    'to taste' => ['to taste', 3, 'to taste'],
    'a rough level' => ['some', 2, 'some'],
    'nothing' => [null, 2, null],
]);

it('adds a recipe with its photos, putting new ingredients in the catalog in their aisle', function () {
    $salt = Ingredient::factory()->create(['name' => 'Kosher salt', 'category' => IngredientCategory::SpicesAndHerbs]);
    Equipment::factory()->create(['name' => 'Tortilla press']);

    $recipe = app(AddRecipe::class)->handle(new RecipeDraft(
        name: 'Corn tortillas',
        source: 'Back of the masa bag',
        servings: 16,
        ingredients: [
            new RecipeLine('Masa harina', '2 cups', category: IngredientCategory::Baking),
            new RecipeLine('Kosher salt', '½ tsp', category: IngredientCategory::Other),
            new RecipeLine('Lime', null, 'for serving', isOptional: true),
        ],
        equipment: ['Tortilla press', 'Comal'],
        steps: ['Mix.', 'Press and cook.'],
    ), [fakeReceiptPhoto('front.jpg'), fakeReceiptPhoto('back.jpg')]);

    expect($recipe)
        ->name->toBe('Corn tortillas')
        ->servings->toBe(16)
        ->steps->toBe(['Mix.', 'Press and cook.'])
        ->photos->toHaveCount(2)
        ->and($recipe->ingredients->pluck('name')->all())->toBe(['Masa harina', 'Kosher salt', 'Lime'])
        ->and($recipe->ingredients[2]->is_optional)->toBeTrue()
        ->and($recipe->equipment->map->isInKitchen()->all())->toBe([true, false]);

    expect(Ingredient::query()->named('Masa harina')->sole()->category)->toBe(IngredientCategory::Baking)
        ->and($salt->fresh()->category)->toBe(IngredientCategory::SpicesAndHerbs)
        ->and(Ingredient::query()->named('Lime')->sole()->category)->toBeNull();

    Storage::assertExists($recipe->photos);
    expect($recipe->photos[0])->toStartWith('recipes/');
});

it('keeps none of the photos when saving a recipe fails', function () {
    RecipeEquipment::saving(fn () => throw new RuntimeException('Disk full'));

    expect(fn () => app(AddRecipe::class)->handle(
        new RecipeDraft('Corn tortillas', ingredients: [new RecipeLine('Masa harina')], equipment: ['Tortilla press']),
        [fakeReceiptPhoto()],
    ))->toThrow(RuntimeException::class, 'Disk full');

    expect(Recipe::count())->toBe(0)
        ->and(Ingredient::count())->toBe(0)
        ->and(Storage::allFiles())->toBe([]);
});

it('deletes its photos with the recipe', function () {
    Storage::put('recipes/card.jpg', 'card');
    $recipe = Recipe::factory()->create(['photos' => ['recipes/card.jpg']]);

    $recipe->delete();

    Storage::assertMissing('recipes/card.jpg');
});

it('puts recipe ingredients on the shopping list with the amount, scaled, when it reads as one', function () {
    $recipe = Recipe::factory()->create();
    $masa = $recipe->ingredients()->create(['name' => 'Masa harina', 'quantity' => '2 cups']);
    $salt = $recipe->ingredients()->create(['name' => 'Kosher salt', 'quantity' => 'to taste']);
    $recipe->ingredients()->create(['name' => 'Lime', 'quantity' => '2']);

    $added = app(AddToShoppingList::class)->fromRecipe($recipe, [$masa->id, $salt->id], 2);

    expect($added)->toBe(['Masa harina', 'Kosher salt'])
        ->and(ShoppingListItem::query()->orderBy('id')->get()->map->only('name', 'quantity', 'ingredient_id')->all())->toBe([
            ['name' => 'Masa harina', 'quantity' => '4 cups', 'ingredient_id' => $masa->ingredient_id],
            ['name' => 'Kosher salt', 'quantity' => null, 'ingredient_id' => $salt->ingredient_id],
        ])
        ->and($recipe->fresh()->ingredientsToBuy()->pluck('name')->all())->toBe(['Lime']);
});
