<?php

use App\Actions\AddRecipe;
use App\Mcp\Servers\MitchenServer;
use App\Mcp\Tools\GetRecipe;
use App\Mcp\Tools\RecipeHistory;
use App\Mcp\Tools\UpdateRecipe;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Support\RecipeDraft;
use App\Support\RecipeLine;
use Illuminate\Testing\Fluent\AssertableJson;

beforeEach(function () {
    $this->cook = User::factory()->create();

    $this->salsa = fn (): Recipe => app(AddRecipe::class)->handle(new RecipeDraft(
        name: 'Salsa',
        servings: 4,
        ingredients: [new RecipeLine('Tomatoes', '4'), new RecipeLine('Kosher salt', '1 tsp'), new RecipeLine('Vinegar', '1 cup')],
        steps: ['Chop the tomatoes.', 'Stir in the salt and 1 cup vinegar.'],
    ));

    // The salsa as the cook's notes would have it next time.
    $this->revised = fn (Recipe $recipe, array $overrides = []): array => [
        'recipe_id' => $recipe->id,
        'name' => 'Salsa',
        'servings' => 4,
        'ingredients' => [
            ['name' => 'Tomatoes', 'quantity' => '4'],
            ['name' => 'Kosher salt', 'quantity' => '1½ tsp'],
            ['name' => 'Vinegar', 'quantity' => '¾ cup'],
        ],
        'steps' => ['Chop the tomatoes.', 'Stir in the salt and ¾ cup vinegar.'],
        ...$overrides,
    ];
});

it('previews a revision without saving it', function () {
    $recipe = ($this->salsa)();
    $log = $recipe->cookLogs()->create(['cooked_on' => '2026-10-28', 'next_time' => 'More salt, less vinegar.']);

    MitchenServer::actingAs($this->cook)
        ->tool(UpdateRecipe::class, ($this->revised)($recipe, ['name' => 'Tomato salsa', 'notes' => 'Better the next day.', 'cook_log_ids' => [$log->id]]))
        ->assertOk()
        ->assertStructuredContent(['saved' => false, 'changes' => [
            'Name: Salsa → Tomato salsa',
            'Notes: changed',
            'Kosher salt: 1 tsp → 1½ tsp',
            'Vinegar: 1 cup → ¾ cup',
            'Changed step 2: Stir in the salt and ¾ cup vinegar.',
        ]]);

    $recipe->refresh();
    expect($recipe)->name->toBe('Salsa')->version->toBe(1)->notes->toBeNull();
    expect($recipe->ingredients->pluck('quantity')->all())->toBe(['4', '1 tsp', '1 cup']);
    expect(RecipeVersion::query()->exists())->toBeFalse();
    expect($log->fresh()->used_in_version)->toBeNull();
});

it('saves a revision as a new version that takes in the cook\'s notes', function () {
    $recipe = ($this->salsa)();
    $log = $recipe->cookLogs()->create(['cooked_on' => '2026-10-28', 'next_time' => 'More salt, less vinegar.']);

    MitchenServer::actingAs($this->cook)
        ->tool(GetRecipe::class, ['recipe_id' => $recipe->id])
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('waiting_notes', [['cook_log_id' => $log->id, 'cooked_on' => '2026-10-28', 'made_as' => 'Version 1', 'next_time' => 'More salt, less vinegar.']])
            ->etc());

    MitchenServer::actingAs($this->cook)
        ->tool(UpdateRecipe::class, ($this->revised)($recipe, [
            'preview' => false,
            'cook_log_ids' => [$log->id],
            'change_note' => 'More salt, less vinegar: the October batch was too sharp.',
        ]))
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('saved', true)
            ->where('version', 2)
            ->where('changes', ['Kosher salt: 1 tsp → 1½ tsp', 'Vinegar: 1 cup → ¾ cup', 'Changed step 2: Stir in the salt and ¾ cup vinegar.'])
            ->where('waiting_notes', [])
            ->etc());

    $recipe->refresh();
    expect($recipe->version)->toBe(2);
    expect($recipe->ingredients->pluck('quantity')->all())->toBe(['4', '1½ tsp', '¾ cup']);
    expect($recipe->versions()->sole())
        ->number->toBe(2)
        ->note->toBe('More salt, less vinegar: the October batch was too sharp.')
        ->previous->ingredients->{1}->quantity->toBe('1 tsp');
    expect($log->fresh()->used_in_version)->toBe(2);
});

it('changes nothing when a revision can\'t be saved', function (array $overrides, string $error) {
    $recipe = ($this->salsa)();
    app(AddRecipe::class)->handle(new RecipeDraft(name: 'Guacamole', ingredients: [new RecipeLine('Avocados', '3')]));

    MitchenServer::actingAs($this->cook)
        ->tool(UpdateRecipe::class, ($this->revised)($recipe, ['preview' => false, ...$overrides]))
        ->assertHasErrors([$error]);

    expect($recipe->fresh()->version)->toBe(1);
    expect($recipe->ingredients()->pluck('quantity')->all())->toBe(['4', '1 tsp', '1 cup']);
})->with([
    'another recipe\'s name' => [['name' => 'guacamole'], 'There\'s already another recipe called Guacamole (id 2).'],
    'nothing in it' => [['ingredients' => [], 'steps' => []], 'A recipe needs ingredients or steps.'],
]);

it('lists a recipe\'s versions, newest first, and shows it as it was', function () {
    $recipe = ($this->salsa)();
    $this->travelTo('2026-10-29');
    MitchenServer::actingAs($this->cook)->tool(UpdateRecipe::class, ($this->revised)($recipe, ['preview' => false, 'change_note' => 'Too sharp.']));
    $this->travelTo('2026-11-01');
    MitchenServer::actingAs($this->cook)->tool(UpdateRecipe::class, ($this->revised)($recipe, ['preview' => false, 'servings' => 6]));

    MitchenServer::actingAs($this->cook)
        ->tool(RecipeHistory::class, ['recipe_id' => $recipe->id])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('current_version', 3)
            ->where('versions', [
                ['version' => 3, 'on' => '2026-11-01', 'why' => null, 'changes' => ['Serves or makes: 4 → 6']],
                ['version' => 2, 'on' => '2026-10-29', 'why' => 'Too sharp.', 'changes' => ['Kosher salt: 1 tsp → 1½ tsp', 'Vinegar: 1 cup → ¾ cup', 'Changed step 2: Stir in the salt and ¾ cup vinegar.']],
            ])
            ->etc());

    MitchenServer::actingAs($this->cook)
        ->tool(RecipeHistory::class, ['recipe_id' => $recipe->id, 'version' => 1])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('current', false)
            ->where('recipe.servings', 4)
            ->where('recipe.ingredients.1.quantity', '1 tsp')
            ->where('recipe.steps.1', 'Stir in the salt and 1 cup vinegar.')
            ->etc());

    MitchenServer::actingAs($this->cook)
        ->tool(RecipeHistory::class, ['recipe_id' => $recipe->id, 'version' => 3])
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('current', true)
            ->where('recipe.servings', 6)
            ->etc());

    MitchenServer::actingAs($this->cook)
        ->tool(RecipeHistory::class, ['recipe_id' => $recipe->id, 'version' => 4])
        ->assertHasErrors(['Salsa has versions 1 to 3.']);
});
