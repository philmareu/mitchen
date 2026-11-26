<?php

use App\Actions\PrepareRecipeDraft;
use App\Enums\Course;
use App\Enums\Diet;
use App\Enums\IngredientCategory;
use App\Models\Equipment;
use App\Models\Ingredient;

it('tidies a recipe, taking catalog and equipment spellings', function () {
    Ingredient::factory()->create(['name' => 'Kosher salt']);
    Ingredient::factory()->create(['name' => 'Limes']);
    Equipment::factory()->create(['name' => 'Cast iron skillet']);

    $draft = app(PrepareRecipeDraft::class)->handle(sentRecipe([
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
    ]));

    expect($draft)
        ->name->toBe('Corn tortillas')
        ->source->toBeNull()
        ->servings->toBeNull()
        ->equipment->toBe(['Cast iron skillet', 'Tortilla press'])
        ->steps->toBe(['Mix.', 'Press and cook.'])
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

it('keeps how the recipe is described, dropping what it doesn\'t know', function () {
    $prepare = app(PrepareRecipeDraft::class);

    expect($prepare->handle(sentRecipe([
        'cuisine' => ' Tex-Mex ',
        'course' => 'brunch',
        'protein' => 'bison',
        'diets' => ['vegan', 'paleo', 'vegan', 'dairy_free'],
        'tags' => ['weeknight', ' ', 'Weeknight', 'Game day'],
    ])))
        ->cuisine->toBe('Tex-Mex')
        ->course->toBeNull()
        ->protein->toBeNull()
        ->diets->toBe([Diet::Vegan, Diet::DairyFree])
        ->tags->toBe(['weeknight', 'Game day']);

    expect($prepare->handle(sentRecipe()))->course->toBe(Course::Bread);
});

it('finds no recipe in one with no ingredients or steps, and names one that has no name', function () {
    $prepare = app(PrepareRecipeDraft::class);

    expect($prepare->handle(sentRecipe(['name' => '', 'ingredients' => [], 'equipment' => [], 'steps' => []]))->isEmpty())->toBeTrue();
    expect($prepare->handle(sentRecipe(['name' => '', 'servings' => 0])))->name->toBe('Untitled recipe')->servings->toBeNull();
    expect($prepare->handle(['name' => 'Soup', 'ingredients' => 'none', 'steps' => null]))->isEmpty()->toBeTrue()->ingredients->toBe([])->equipment->toBe([]);
});
