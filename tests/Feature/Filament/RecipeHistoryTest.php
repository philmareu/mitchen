<?php

use App\Filament\Resources\Recipes\Pages\EditRecipe;
use App\Filament\Resources\Recipes\Pages\ListRecipes;
use App\Filament\Resources\Recipes\Pages\ViewRecipe;
use App\Filament\Resources\Recipes\RelationManagers\CookLogsRelationManager;
use App\Filament\Resources\Recipes\RelationManagers\VersionsRelationManager;
use App\Models\CookLog;
use App\Models\Recipe;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;

use function Pest\Livewire\livewire;

beforeEach(fn () => actingAsCook());

/**
 * Pickled red onions with salt as the third line.
 */
function onionsRecipe(): Recipe
{
    $recipe = Recipe::factory()->create(['name' => 'Pickled red onions', 'servings' => 1, 'steps' => ['Slice.', 'Pickle 3 days.']]);
    $recipe->ingredients()->create(['name' => 'Red onion', 'quantity' => '1 large', 'sort' => 0]);
    $recipe->ingredients()->create(['name' => 'Apple cider vinegar', 'quantity' => '1 cup', 'sort' => 1]);
    $recipe->ingredients()->create(['name' => 'Kosher salt', 'quantity' => '1 tsp', 'sort' => 2]);

    return $recipe;
}

it('logs a cook from the recipe page', function () {
    $recipe = onionsRecipe();

    livewire(ViewRecipe::class, ['record' => $recipe->getRouteKey()])
        ->callAction('madeIt', data: ['cooked_on' => '2026-10-28', 'thoughts' => 'Too sharp.', 'next_time' => 'More salt.'])
        ->assertHasNoFormErrors()
        ->assertNotified('Added to the cook log')
        ->assertDispatched('cook-log-updated')
        ->assertActionExists('edit', fn (EditAction $action): bool => (string) $action->getBadge() === '1');

    expect($recipe->cookLogs()->sole())
        ->cooked_on->toDateString()->toBe('2026-10-28')
        ->version->toBe(1)
        ->next_time->toBe('More salt.');
});

it('adds thoughts to a cook days later', function () {
    $recipe = onionsRecipe();
    $log = CookLog::factory()->for($recipe)->create(['thoughts' => null]);

    livewire(CookLogsRelationManager::class, ['ownerRecord' => $recipe, 'pageClass' => ViewRecipe::class])
        ->assertCanSeeTableRecords([$log])
        ->callAction(TestAction::make('edit')->table($log), data: ['thoughts' => 'Better on day three.', 'next_time' => 'More salt.'])
        ->assertHasNoFormErrors()
        ->assertDispatched('cook-log-updated');

    expect($log->fresh())->thoughts->toBe('Better on day three.')->next_time->toBe('More salt.');
});

it('keeps the old version when the recipe is edited, with the notes it took in', function () {
    $recipe = onionsRecipe();
    $log = CookLog::factory()->for($recipe)->nextTime('More salt.')->create();

    livewire(EditRecipe::class, ['record' => $recipe->getRouteKey()])
        ->assertSchemaStateSet(['new_version' => true])
        ->fillForm(function (array $state) use ($log): array {
            $state['ingredients'][array_keys($state['ingredients'])[2]]['quantity'] = '1½ tsp';
            $state['version_note'] = 'Saltier.';
            $state['used_notes'] = [(string) $log->id];

            return $state;
        })
        ->call('save')
        ->assertHasNoFormErrors();

    expect($recipe->fresh()->version)->toBe(2)
        ->and($recipe->versions()->sole())
        ->note->toBe('Saltier.')
        ->changes->toBe(['Kosher salt: 1 tsp → 1½ tsp'])
        ->and($log->fresh()->used_in_version)->toBe(2);

    livewire(VersionsRelationManager::class, ['ownerRecord' => $recipe->fresh(), 'pageClass' => ViewRecipe::class])
        ->assertSee('This is version 2.')
        ->assertSee('Kosher salt: 1 tsp → 1½ tsp')
        ->assertActionHasLabel(TestAction::make('previous')->table($recipe->versions()->sole()), 'See version 1')
        ->mountAction(TestAction::make('previous')->table($recipe->versions()->sole()))
        ->assertActionMounted(TestAction::make('previous')->table($recipe->versions()->sole()));
});

it('fixes a mistake without making a new version', function () {
    $recipe = onionsRecipe();

    livewire(EditRecipe::class, ['record' => $recipe->getRouteKey()])
        ->fillForm(function (array $state): array {
            $state['steps'][array_key_first($state['steps'])]['step'] = 'Slice thin.';
            $state['new_version'] = false;

            return $state;
        })
        ->call('save')
        ->assertHasNoFormErrors();

    expect($recipe->fresh())->version->toBe(1)->steps->toBe(['Slice thin.', 'Pickle 3 days.'])
        ->and($recipe->versions()->count())->toBe(0);
});

it('shows when each recipe was last made', function () {
    $recipe = onionsRecipe();
    CookLog::factory()->for($recipe)->create(['cooked_on' => '2026-10-01']);
    CookLog::factory()->for($recipe)->create(['cooked_on' => '2026-10-20']);

    livewire(ListRecipes::class)
        ->assertTableColumnFormattedStateSet('cook_logs_max_cooked_on', 'Oct 20, 2026', $recipe);
});
