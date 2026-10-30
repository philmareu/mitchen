<?php

use App\Actions\RecordRecipeVersion;
use App\Actions\ReviseRecipe;
use App\Actions\UpdateRecipe;
use App\Ai\Agents\RecipeReviser;
use App\Enums\IngredientCategory;
use App\Models\CookLog;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Support\RecipeChanges;
use App\Support\RecipeDraft;
use App\Support\RecipeLine;
use Laravel\Ai\Prompts\AgentPrompt;

/**
 * Pickled red onions, version 1.
 */
function pickledOnions(): Recipe
{
    $recipe = Recipe::factory()->create([
        'name' => 'Pickled red onions',
        'servings' => 1,
        'steps' => ['Slice the onion thin.', 'Pour the hot brine over the onion and let it sit 3 days.'],
    ]);
    $recipe->ingredients()->create(['name' => 'Red onion', 'quantity' => '1 large', 'note' => 'thinly sliced', 'sort' => 0]);
    $recipe->ingredients()->create(['name' => 'Apple cider vinegar', 'quantity' => '1 cup', 'sort' => 1]);
    $recipe->ingredients()->create(['name' => 'Kosher salt', 'quantity' => '1 tsp', 'sort' => 2]);
    $recipe->equipment()->create(['name' => 'Mason jar']);

    return $recipe;
}

/**
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function onionSnapshot(array $changes = []): array
{
    return [
        'servings' => 1,
        'ingredients' => [
            ['quantity' => '1 large', 'name' => 'Red onion', 'note' => 'thinly sliced', 'is_optional' => false],
            ['quantity' => '1 cup', 'name' => 'Apple cider vinegar', 'note' => null, 'is_optional' => false],
            ['quantity' => '1 tsp', 'name' => 'Kosher salt', 'note' => null, 'is_optional' => false],
        ],
        'equipment' => ['Mason jar'],
        'steps' => ['Slice the onion thin.', 'Pour the hot brine over the onion and let it sit 3 days.'],
        ...$changes,
    ];
}

it('says what changed between two versions', function (array $after, array $changes) {
    expect(RecipeChanges::between(onionSnapshot(), onionSnapshot($after)))->toBe($changes);
})->with([
    'nothing' => [[], []],
    'servings' => [['servings' => 2], ['Serves or makes: 1 → 2']],
    'amounts, notes and optional' => [
        ['ingredients' => [
            ['quantity' => '1 large', 'name' => 'red onion', 'note' => 'in rings', 'is_optional' => false],
            ['quantity' => '¾ cup', 'name' => 'Apple cider vinegar', 'note' => null, 'is_optional' => false],
            ['quantity' => '1½ tsp', 'name' => 'Kosher salt', 'note' => 'or sea salt', 'is_optional' => true],
        ]],
        [
            'red onion: thinly sliced → in rings',
            'Apple cider vinegar: 1 cup → ¾ cup',
            'Kosher salt: 1 tsp → 1½ tsp, or sea salt, now optional',
        ],
    ],
    'added and removed ingredients, ignoring order' => [
        ['ingredients' => [
            ['quantity' => '1 tsp', 'name' => 'Kosher salt', 'note' => null, 'is_optional' => false],
            ['quantity' => '1 large', 'name' => 'Red onion', 'note' => 'thinly sliced', 'is_optional' => false],
            ['quantity' => '½ tsp', 'name' => 'Black peppercorns', 'note' => null, 'is_optional' => false],
        ]],
        ['Added Black peppercorns (½ tsp)', 'Removed Apple cider vinegar'],
    ],
    'equipment' => [['equipment' => ['mason jar', 'Mandoline']], ['Added Mandoline']],
    'a changed step' => [
        ['steps' => ['Slice the onion thin.', 'Pour the hot brine over the onion and let it sit 2 days.']],
        ['Changed step 2: Pour the hot brine over the onion and let it sit 2 days.'],
    ],
    'an added and a removed step' => [
        ['steps' => ['Bring the vinegar and salt to a boil.', 'Pour the hot brine over the onion and let it sit 3 days.']],
        ['Changed step 1: Bring the vinegar and salt to a boil.'],
    ],
    'a step added in the middle' => [
        ['steps' => ['Slice the onion thin.', 'Bring the vinegar and salt to a boil.', 'Pour the hot brine over the onion and let it sit 3 days.']],
        ['Added step 2: Bring the vinegar and salt to a boil.'],
    ],
    'a step removed' => [
        ['steps' => ['Pour the hot brine over the onion and let it sit 3 days.']],
        ['Removed step 1: Slice the onion thin.'],
    ],
]);

it('keeps the old version when the lines change, and marks the notes it took in', function () {
    $recipe = pickledOnions();
    $salty = CookLog::factory()->for($recipe)->nextTime('More salt.')->create();
    $later = CookLog::factory()->for($recipe)->nextTime('Try a mandoline.')->create();
    $before = $recipe->snapshot();

    $recipe->ingredients()->where('name', 'Kosher salt')->sole()->update(['quantity' => '1½ tsp']);

    $version = app(RecordRecipeVersion::class)->handle($recipe, $before, 'Saltier.', [$salty->id]);

    expect($version)
        ->number->toBe(2)
        ->note->toBe('Saltier.')
        ->changes->toBe(['Kosher salt: 1 tsp → 1½ tsp'])
        ->previous->toBe($before)
        ->and($recipe->fresh()->version)->toBe(2)
        ->and($salty->fresh()->used_in_version)->toBe(2)
        ->and($later->fresh()->used_in_version)->toBeNull()
        ->and($recipe->waitingNotes()->modelKeys())->toBe([$later->id]);
});

it('makes no version when only the name or notes changed', function () {
    $recipe = pickledOnions();
    $before = $recipe->snapshot();

    $recipe->update(['name' => 'Quick pickled onions', 'notes' => 'Keeps 2 weeks.']);

    expect(app(RecordRecipeVersion::class)->handle($recipe, $before))->toBeNull()
        ->and($recipe->fresh()->version)->toBe(1)
        ->and($recipe->versions()->count())->toBe(0);
});

it('logs a cook against the current version, and waits for notes that have something in them', function () {
    $recipe = pickledOnions();
    $recipe->forceFill(['version' => 3])->save();

    $log = $recipe->cookLogs()->create(['cooked_on' => '2026-10-01', 'thoughts' => 'Too sharp.', 'next_time' => 'Less vinegar.']);
    $recipe->cookLogs()->create(['cooked_on' => '2026-10-05', 'thoughts' => 'Perfect.']);

    expect($log->version)->toBe(3)
        ->and($log->summary())->toBe('Oct 1 · version 3: Less vinegar.')
        ->and($recipe->waitingNotes()->modelKeys())->toBe([$log->id]);
});

it('asks the assistant to work the notes into the recipe', function () {
    $recipe = pickledOnions();
    $log = CookLog::factory()->for($recipe)->create(['cooked_on' => '2026-10-01', 'thoughts' => 'Too sharp.', 'next_time' => 'More salt, less vinegar.']);

    RecipeReviser::fake([readerRecipe([
        'name' => 'Pickled red onions',
        'source' => null,
        'servings' => 1,
        'ingredients' => [
            ['quantity' => '1 large', 'name' => 'red onion', 'note' => 'thinly sliced', 'optional' => false, 'category' => 'produce'],
            ['quantity' => '¾ cup', 'name' => 'Apple cider vinegar', 'note' => null, 'optional' => false, 'category' => 'oils_and_condiments'],
            ['quantity' => '1½ tsp', 'name' => 'Kosher salt', 'note' => null, 'optional' => false, 'category' => 'spices_and_herbs'],
        ],
        'equipment' => ['Mason jar'],
        'summary' => 'More salt and a little less vinegar: it was too sharp.',
    ])]);

    $draft = app(ReviseRecipe::class)->handle($recipe, [$log->id], 'Make it for two jars.');

    RecipeReviser::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('"quantity": "1 tsp"')
        && $prompt->contains('Made Oct 1, 2026 (version 1). How it turned out: Too sharp. Next time: More salt, less vinegar.')
        && $prompt->contains('Make it for two jars.'));

    expect($draft)
        ->changeNote->toBe('More salt and a little less vinegar: it was too sharp.')
        ->and($draft->ingredients[0]->name)->toBe('Red onion')
        ->and($draft->toArray()['version_note'])->toBe('More salt and a little less vinegar: it was too sharp.');
});

it('saves a revised recipe as the next version', function () {
    $recipe = pickledOnions();
    $log = CookLog::factory()->for($recipe)->nextTime('More salt.')->create();

    $version = app(UpdateRecipe::class)->handle($recipe, new RecipeDraft(
        name: 'Pickled red onions',
        servings: 1,
        ingredients: [
            new RecipeLine('Red onion', '1 large', 'thinly sliced'),
            new RecipeLine('Apple cider vinegar', '1 cup'),
            new RecipeLine('Kosher salt', '1½ tsp'),
            new RecipeLine('Black peppercorns', '½ tsp', category: IngredientCategory::SpicesAndHerbs),
        ],
        equipment: ['Mason jar'],
        steps: $recipe->steps,
    ), 'Saltier, with pepper.', [$log->id]);

    expect($version->changes)->toBe(['Kosher salt: 1 tsp → 1½ tsp', 'Added Black peppercorns (½ tsp)'])
        ->and($recipe->fresh()->version)->toBe(2)
        ->and($recipe->fresh()->ingredients->pluck('quantity')->all())->toBe(['1 large', '1 cup', '1½ tsp', '½ tsp'])
        ->and(Ingredient::query()->named('Black peppercorns')->sole()->category)->toBe(IngredientCategory::SpicesAndHerbs)
        ->and($log->fresh()->used_in_version)->toBe(2);
});
