<?php

use App\Actions\ReadRecipe;
use App\Ai\Agents\RecipeReader;
use App\Enums\Course;
use App\Enums\Diet;
use App\Enums\IngredientCategory;
use App\Models\Equipment;
use App\Models\Ingredient;
use App\Models\Recipe;
use Illuminate\Http\UploadedFile;
use Laravel\Ai\Prompts\AgentPrompt;

it('sends the note with the catalog and the equipment', function () {
    Ingredient::factory()->create(['name' => 'Kosher salt']);
    Ingredient::factory()->create(['name' => 'All-purpose flour']);
    Equipment::factory()->create(['name' => 'Cast iron skillet']);

    RecipeReader::fake([readerRecipe()]);

    app(ReadRecipe::class)->handle('Grandma\'s tortillas, makes 16');

    RecipeReader::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('Grandma\'s tortillas, makes 16')
        && $prompt->contains('Photos attached: none')
        && $prompt->contains('Ingredients in the catalog: All-purpose flour, Kosher salt')
        && $prompt->contains('Equipment the kitchen has: Cast iron skillet'));
});

it('sends the photos in order', function () {
    $photos = [fakeReceiptPhoto('front.jpg'), fakeReceiptPhoto('back.jpg')];

    RecipeReader::fake([readerRecipe()]);

    app(ReadRecipe::class)->handle('', array_map(fn (UploadedFile $photo): string => $photo->getRealPath(), $photos));

    RecipeReader::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->attachments->count() === 2
        && $prompt->contains('(no note, just the photos)')
        && $prompt->contains('Photos attached: 2')
        && $prompt->contains('Ingredients in the catalog: (empty)')
        && $prompt->contains('Equipment the kitchen has: (none listed)'));
});

it('tidies the answer, taking catalog and equipment spellings', function () {
    Ingredient::factory()->create(['name' => 'Kosher salt']);
    Ingredient::factory()->create(['name' => 'Limes']);
    Equipment::factory()->create(['name' => 'Cast iron skillet']);

    RecipeReader::fake([readerRecipe([
        'name' => ' Corn tortillas ',
        'source' => ' ',
        'servings' => 'lots',
        'ingredients' => [
            ['quantity' => ' 2 cups ', 'name' => 'masa harina', 'note' => '', 'optional' => false, 'category' => 'baking'],
            ['quantity' => null, 'name' => 'kosher SALT', 'note' => 'to taste', 'optional' => 'yes', 'category' => 'nonsense'],
            ['quantity' => '1', 'name' => ' ', 'note' => null, 'optional' => false, 'category' => 'other'],
            ['quantity' => null, 'name' => 'Lime', 'note' => 'for serving', 'optional' => true, 'category' => 'produce'],
            'a stray string',
        ],
        'equipment' => ['cast iron skillet', 'Tortilla press', 'tortilla press', ''],
        'steps' => ['Mix.', ' ', 'Press and cook.'],
        'not_understood' => [' smudged amount ', '', 42],
    ])]);

    $draft = app(ReadRecipe::class)->handle('tortillas');

    expect($draft)
        ->name->toBe('Corn tortillas')
        ->source->toBeNull()
        ->servings->toBeNull()
        ->equipment->toBe(['Cast iron skillet', 'Tortilla press'])
        ->steps->toBe(['Mix.', 'Press and cook.'])
        ->notUnderstood->toBe(['smudged amount'])
        ->and($draft->ingredients)->toHaveCount(3);

    [$masa, $salt, $lime] = $draft->ingredients;

    expect($masa)
        ->name->toBe('Masa harina')
        ->quantity->toBe('2 cups')
        ->note->toBeNull()
        ->category->toBe(IngredientCategory::Baking);

    expect($salt)
        ->name->toBe('Kosher salt')
        ->quantity->toBeNull()
        ->note->toBe('to taste')
        ->isOptional->toBeFalse()
        ->category->toBe(IngredientCategory::Other);

    expect($lime)->name->toBe('Limes')->isOptional->toBeTrue();
});

it('reads how the recipe is described, dropping what it doesn\'t know, and sends the cuisines and tags in use', function () {
    Recipe::factory()->create(['cuisine' => 'Mexican', 'tags' => ['Weeknight']]);

    RecipeReader::fake([readerRecipe([
        'cuisine' => ' Tex-Mex ',
        'course' => 'brunch',
        'protein' => 'bison',
        'diets' => ['vegan', 'paleo', 'vegan', 'dairy_free'],
        'tags' => ['weeknight', ' ', 'Weeknight', 'Game day'],
    ])]);

    $draft = app(ReadRecipe::class)->handle('tortillas');

    RecipeReader::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('Cuisines the cook\'s recipes use: Mexican')
        && $prompt->contains('Tags the cook\'s recipes use: Weeknight'));

    expect($draft)
        ->cuisine->toBe('Tex-Mex')
        ->course->toBeNull()
        ->protein->toBeNull()
        ->diets->toBe([Diet::Vegan, Diet::DairyFree])
        ->tags->toBe(['weeknight', 'Game day']);

    RecipeReader::fake([readerRecipe()]);

    expect(app(ReadRecipe::class)->handle('tortillas'))->course->toBe(Course::Bread);
});

it('finds no recipe in an empty answer, and names one that has no name', function () {
    RecipeReader::fake([
        readerRecipe(['name' => '', 'ingredients' => [], 'equipment' => [], 'steps' => []]),
        readerRecipe(['name' => '', 'servings' => 0]),
        ['name' => 'Soup', 'ingredients' => 'none', 'steps' => null],
    ]);

    $read = app(ReadRecipe::class);

    expect($read->handle('a grocery list')->isEmpty())->toBeTrue();
    expect($read->handle('tortillas'))->name->toBe('Untitled recipe')->servings->toBeNull();
    expect($read->handle('soup'))->isEmpty()->toBeTrue()->ingredients->toBe([])->equipment->toBe([]);
});
