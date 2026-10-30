<?php

use App\Ai\Agents\RecipeReader;
use App\Enums\IngredientCategory;
use App\Enums\StorageLocation;
use App\Filament\Resources\Recipes\Pages\CreateRecipe;
use App\Filament\Resources\Recipes\Pages\EditRecipe;
use App\Filament\Resources\Recipes\Pages\ListRecipes;
use App\Filament\Resources\Recipes\Pages\ViewRecipe;
use App\Filament\Resources\Recipes\RecipeResource;
use App\Models\Equipment;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\ShoppingListItem;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Prompts\AgentPrompt;

use function Pest\Livewire\livewire;

beforeEach(function () {
    actingAsCook();
    config(['ai.providers.anthropic.key' => 'test-key']);
    Storage::fake();
});

/**
 * A recipe for tortillas: masa harina is out, salt is on hand, oil is low, lime is optional
 * and out, and the kitchen has the skillet but no tortilla press.
 */
function tortillas(): Recipe
{
    $salt = Ingredient::factory()->create(['name' => 'Kosher salt']);
    $oil = Ingredient::factory()->create(['name' => 'Olive oil']);
    InventoryItem::factory()->for($salt)->create(['quantity' => 'plenty', 'location' => StorageLocation::Pantry]);
    InventoryItem::factory()->for($oil)->create(['quantity' => 'low']);
    Equipment::factory()->create(['name' => 'Cast iron skillet']);

    $recipe = Recipe::factory()->create(['name' => 'Corn tortillas', 'servings' => 16, 'steps' => ['Mix.', 'Press and cook.']]);
    $recipe->ingredients()->create(['name' => 'Masa harina', 'quantity' => '2 cups']);
    $recipe->ingredients()->create(['name' => 'Kosher salt', 'quantity' => '½ tsp']);
    $recipe->ingredients()->create(['name' => 'Olive oil', 'quantity' => '1 tbsp']);
    RecipeIngredient::factory()->optional()->for($recipe)->create(['name' => 'Lime', 'quantity' => '1']);
    $recipe->equipment()->create(['name' => 'Cast iron skillet']);
    $recipe->equipment()->create(['name' => 'Tortilla press']);

    return $recipe;
}

function recipePageUrl(Recipe $recipe): string
{
    return RecipeResource::getUrl('view', ['record' => $recipe]);
}

it('lists recipes with what they are missing, and filters to the ones ready to cook', function () {
    $tortillas = tortillas();
    $toast = Recipe::factory()->create(['name' => 'Toast']);

    $this->get('/recipes')->assertOk();

    livewire(ListRecipes::class)
        ->assertCanSeeTableRecords([$tortillas, $toast])
        ->assertTableColumnStateSet('ready', 'Missing 2', $tortillas)
        ->assertTableColumnStateSet('ready', 'Ready to cook', $toast)
        ->assertSeeHtml('href="'.e(recipePageUrl($tortillas)).'"')
        ->filterTable('ready_to_cook')
        ->assertCanSeeTableRecords([$toast])
        ->assertCanNotSeeTableRecords([$tortillas]);
});

it('adds a recipe by hand', function () {
    $salt = Ingredient::factory()->create(['name' => 'Kosher salt']);

    livewire(CreateRecipe::class)
        ->fillForm([
            'name' => 'Corn tortillas',
            'servings' => 16,
            'ingredients' => [
                ['quantity' => '2 cups', 'name' => 'Masa harina', 'note' => null, 'is_optional' => false],
                ['quantity' => '½ tsp', 'name' => 'kosher salt', 'note' => null, 'is_optional' => false],
            ],
            'equipment' => [['name' => 'Tortilla press']],
            // A simple repeater's items each hold their value under the field's name.
            'steps' => [['step' => 'Mix.'], ['step' => 'Press and cook.']],
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $recipe = Recipe::sole();

    expect($recipe)
        ->name->toBe('Corn tortillas')
        ->servings->toBe(16)
        ->steps->toBe(['Mix.', 'Press and cook.'])
        ->and($recipe->ingredients->pluck('name')->all())->toBe(['Masa harina', 'Kosher salt'])
        ->and($recipe->ingredients[1]->ingredient_id)->toBe($salt->id)
        ->and($recipe->equipment->sole()->name)->toBe('Tortilla press');
});

it('edits a recipe, linking a renamed ingredient', function () {
    $recipe = tortillas();

    livewire(EditRecipe::class, ['record' => $recipe->getRouteKey()])
        ->fillForm(function (array $state): array {
            $first = array_key_first($state['ingredients']);
            $state['ingredients'][$first]['name'] = 'olive oil';

            return $state;
        })
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertRedirect(recipePageUrl($recipe));

    expect($recipe->ingredients()->first())->name->toBe('Olive oil')->availability()->getLabel()->toBe('Running low');
});

it('shows what is in the kitchen for each ingredient and piece of equipment', function () {
    $recipe = tortillas();

    $this->get(recipePageUrl($recipe))
        ->assertOk()
        ->assertSeeInOrder(['2 cups', 'Masa harina', 'Out'])
        ->assertSeeInOrder(['Kosher salt', 'On hand', 'Full · Pantry'])
        ->assertSeeInOrder(['Olive oil', 'Running low'])
        ->assertSeeInOrder(['Lime', 'optional'])
        ->assertSeeInOrder(['Cast iron skillet', 'Have it', 'Tortilla press', 'Don&#039;t have'], escape: false)
        ->assertSeeInOrder(['1. Mix.', '2. Press and cook.']);
});

it('scales the amounts', function () {
    $recipe = tortillas();

    livewire(ViewRecipe::class, ['record' => $recipe->getRouteKey()])
        ->assertSee('2 cups')
        ->callAction('scale', data: ['scale' => '0.5'])
        ->assertSet('scale', 0.5)
        ->assertSee('1 cup')
        ->assertSee('¼ tsp')
        ->assertSee('Scaled ½×');
});

it('puts what is out on the shopping list, and what is low when ticked', function () {
    $recipe = tortillas();
    [$masa, , $oil] = $recipe->ingredients;

    livewire(ViewRecipe::class, ['record' => $recipe->getRouteKey()])
        ->mountAction('addToShoppingList')
        ->assertSchemaStateSet(['lines' => [(string) $masa->id]])
        ->setActionData(['lines' => [(string) $masa->id, (string) $oil->id]])
        ->callMountedAction()
        ->assertHasNoFormErrors()
        ->assertNotified(Notification::make()->title('Added to the shopping list')->body('Masa harina, Olive oil')->success());

    expect(ShoppingListItem::query()->orderBy('id')->get()->map->only('name', 'quantity')->all())->toBe([
        ['name' => 'Masa harina', 'quantity' => '2 cups'],
        ['name' => 'Olive oil', 'quantity' => '1 tbsp'],
    ]);
});

it('has nothing to add to the shopping list when everything is on hand', function () {
    $recipe = Recipe::factory()->create();
    $recipe->ingredients()->create(['name' => 'Eggs']);
    InventoryItem::factory()->for(Ingredient::query()->named('Eggs')->sole())->create(['quantity' => 'a dozen']);

    livewire(ViewRecipe::class, ['record' => $recipe->getRouteKey()])
        ->assertActionDisabled('addToShoppingList');
});

it('deletes a recipe with its photos', function () {
    Storage::put('recipes/card.jpg', 'card');
    $recipe = Recipe::factory()->create(['photos' => ['recipes/card.jpg']]);

    livewire(ViewRecipe::class, ['record' => $recipe->getRouteKey()])
        ->callAction('delete');

    $this->assertModelMissing($recipe);
    Storage::assertMissing('recipes/card.jpg');
});

it('reads photos of a recipe card for the cook to check, then saves it with the photos', function () {
    Ingredient::factory()->create(['name' => 'Kosher salt', 'category' => IngredientCategory::SpicesAndHerbs]);

    RecipeReader::fake([readerRecipe(['not_understood' => ['a smudge by the salt']])]);

    livewire(ListRecipes::class)
        ->mountAction('quickAddRecipe')
        ->fillForm(['photos' => [fakeReceiptPhoto('front.jpg'), fakeReceiptPhoto('back.jpg')]])
        ->goToNextWizardStep()
        ->assertHasNoFormErrors()
        ->assertWizardCurrentStep(2)
        ->assertSchemaStateSet([
            'name' => 'Corn tortillas',
            'servings' => 16,
            'not_understood' => ['a smudge by the salt'],
        ])
        ->assertSchemaStateSet(fn (array $state): bool => count($state['ingredients']) === 3
            && $state['ingredients'][0]['category'] === 'baking'
            && count($state['steps']) === 2)
        ->callMountedAction()
        ->assertHasNoFormErrors()
        ->assertNotified('Recipe saved')
        ->assertRedirect(recipePageUrl(Recipe::sole()));

    RecipeReader::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->attachments->count() === 2);

    $recipe = Recipe::sole();

    expect($recipe)
        ->name->toBe('Corn tortillas')
        ->source->toBe('Back of the masa bag')
        ->steps->toHaveCount(2)
        ->photos->toHaveCount(2)
        ->and($recipe->ingredients->pluck('name')->all())->toBe(['Masa harina', 'Water', 'Kosher salt'])
        ->and(Ingredient::query()->named('Masa harina')->sole()->category)->toBe(IngredientCategory::Baking)
        ->and($recipe->equipment->sole()->name)->toBe('Tortilla press');

    Storage::assertExists($recipe->photos);
});

it('reads a typed recipe, lets the cook correct it, then saves it', function () {
    RecipeReader::fake([readerRecipe(['source' => null])]);

    livewire(ListRecipes::class)
        ->mountAction('quickAddRecipe')
        ->fillForm(['note' => "Corn tortillas\n2 cups masa harina, 1½ cups warm water, ½ tsp salt"])
        ->goToNextWizardStep()
        ->assertWizardCurrentStep(2)
        ->fillForm(function (array $state): array {
            $state['servings'] = 12;
            $state['ingredients'][1]['quantity'] = '1⅓ cups';

            return $state;
        })
        ->callMountedAction()
        ->assertHasNoFormErrors();

    RecipeReader::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('2 cups masa harina'));

    expect(Recipe::sole())
        ->servings->toBe(12)
        ->photos->toBe([])
        ->and(Recipe::sole()->ingredients[1]->quantity)->toBe('1⅓ cups');
});

it('stays on the first step when the assistant finds no recipe', function () {
    RecipeReader::fake([readerRecipe(['name' => '', 'ingredients' => [], 'steps' => []])]);

    livewire(ListRecipes::class)
        ->mountAction('quickAddRecipe')
        ->fillForm(['note' => 'milk, eggs, bread'])
        ->goToNextWizardStep()
        ->assertWizardCurrentStep(1)
        ->assertNotified('No recipe found');

    expect(Recipe::count())->toBe(0);
});

it('stays on the first step when the assistant cannot be reached', function () {
    RecipeReader::fake(fn () => throw new RuntimeException('Connection refused'));

    livewire(ListRecipes::class)
        ->mountAction('quickAddRecipe')
        ->fillForm(['note' => 'tortillas'])
        ->goToNextWizardStep()
        ->assertWizardCurrentStep(1)
        ->assertNotified('The assistant couldn\'t read that right now');
});

it('needs a note or a photo', function () {
    livewire(ListRecipes::class)
        ->mountAction('quickAddRecipe')
        ->goToNextWizardStep()
        ->assertHasFormErrors(['note' => 'required_without'])
        ->assertWizardCurrentStep(1);

    RecipeReader::assertNeverPrompted();
});

it('needs an API key', function () {
    config(['ai.providers.anthropic.key' => null]);

    livewire(ListRecipes::class)->assertActionDisabled('quickAddRecipe');
});
