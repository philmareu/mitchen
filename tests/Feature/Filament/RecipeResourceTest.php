<?php

use App\Actions\AddRecipe;
use App\Ai\Agents\RecipeReader;
use App\Enums\IngredientCategory;
use App\Enums\Readiness;
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
use App\Support\RecipeDraft;
use App\Support\RecipeLine;
use App\Support\VariantDraft;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Tabs\Tab;
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

it('lists recipes with what it takes to make them, in tabs', function () {
    $tortillas = tortillas();
    $toast = Recipe::factory()->create(['name' => 'Toast']);
    $soup = Recipe::factory()->create(['name' => 'Leek soup']);
    $soup->ingredients()->create(['name' => 'Leeks']);

    $this->get('/recipes')->assertOk();

    $list = livewire(ListRecipes::class)
        ->assertCanSeeTableRecords([$tortillas, $toast, $soup])
        ->assertTableColumnStateSet('ready', Readiness::NeedsEquipment, $tortillas)
        ->assertTableColumnHasDescription('ready', 'Tortilla press, Masa harina', $tortillas)
        ->assertTableColumnStateSet('ready', Readiness::Ready, $toast)
        ->assertTableColumnStateSet('ready', Readiness::QuickShop, $soup)
        ->assertSeeHtml('href="'.e(recipePageUrl($tortillas)).'"');

    expect(collect($list->instance()->getCachedTabs())->map(fn (Tab $tab): mixed => $tab->getBadge())->all())->toBe([
        'all' => null,
        'ready' => '1',
        'quick_shop' => '1',
        'big_shop' => null,
        'plan_ahead' => null,
        'needs_equipment' => '1',
    ]);

    $list->set('activeTab', 'ready')
        ->assertCanSeeTableRecords([$toast])
        ->assertCanNotSeeTableRecords([$tortillas, $soup])
        ->set('activeTab', 'quick_shop')
        ->assertCanSeeTableRecords([$soup])
        ->assertCanNotSeeTableRecords([$tortillas, $toast]);
});

it('says on the recipe page what it takes, and what getting each ingredient takes', function () {
    Ingredient::factory()->makeAhead(3)->create(['name' => 'Pickled red onions']);
    $tacos = app(AddRecipe::class)->handle(new RecipeDraft(
        name: 'Tacos',
        ingredients: [new RecipeLine('Tortillas', '8'), new RecipeLine('Pickled red onions', '¼ cup'), new RecipeLine('Cilantro', 'a handful')],
        variants: [new VariantDraft('Plain', [new RecipeLine('Pickled red onions', isLeftOut: true), new RecipeLine('Cilantro', isLeftOut: true)])],
    ));
    InventoryItem::factory()->for(Ingredient::query()->named('Tortillas')->sole())->create(['quantity' => 'a dozen']);

    $this->get(recipePageUrl($tacos))
        ->assertOk()
        ->assertSee('Plan ahead: Pickled red onions (make ahead, about 3 days), Cilantro. Ready to cook as Plain.')
        ->assertSeeInOrder(['Pickled red onions', 'make ahead, about 3 days', 'Out', 'Cilantro', 'Out']);

    livewire(ViewRecipe::class, ['record' => $tacos->getRouteKey()])
        ->callAction('variant', data: ['variant' => (string) $tacos->variants->sole()->id])
        ->assertSee('Ready to cook.')
        ->assertDontSee('Plan ahead');
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

it('adds a substitute by hand and shows it under the ingredient', function () {
    InventoryItem::factory()->for(Ingredient::factory()->create(['name' => 'Milk']))->create(['quantity' => 'half a gallon']);
    InventoryItem::factory()->for(Ingredient::factory()->create(['name' => 'Lemon juice']))->create(['quantity' => 'plenty']);

    livewire(CreateRecipe::class)
        ->fillForm([
            'name' => 'Buttermilk pancakes',
            'ingredients' => [
                ['quantity' => '1 cup', 'name' => 'Buttermilk', 'note' => null, 'is_optional' => false],
                ['quantity' => '1', 'name' => 'Eggs', 'note' => null, 'is_optional' => false],
            ],
            'substitutes' => [
                ['instead_of' => 'Buttermilk', 'note' => 'Let it sit 5 minutes.', 'ingredients' => [
                    ['quantity' => '1 cup', 'name' => 'milk'],
                    ['quantity' => '1 tbsp', 'name' => 'Lemon juice'],
                ]],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $recipe = Recipe::sole()->load(Recipe::KITCHEN);
    [$buttermilk, $eggs] = $recipe->ingredients;

    expect($recipe->substitutes->sole()->describe())->toBe('1 cup Milk + 1 tbsp Lemon juice')
        ->and($recipe->missing())->toBe(['Eggs']);

    $this->get(recipePageUrl($recipe))
        ->assertOk()
        ->assertSeeInOrder(['Buttermilk', 'Or 1 cup Milk + 1 tbsp Lemon juice (Let it sit 5 minutes.)', 'Substitute on hand', 'Eggs', 'Out']);

    livewire(ViewRecipe::class, ['record' => $recipe->getRouteKey()])
        ->mountAction('addToShoppingList')
        ->assertSchemaStateSet(['lines' => [(string) $eggs->id]]);

    livewire(EditRecipe::class, ['record' => $recipe->getRouteKey()])
        ->assertSchemaStateSet(fn (array $state): bool => array_values($state['substitutes'])[0]['instead_of'] === 'Buttermilk'
            && count(array_values($state['substitutes'])[0]['ingredients']) === 2);
});

it('only offers the recipe\'s ingredients to substitute', function () {
    livewire(CreateRecipe::class)
        ->fillForm([
            'name' => 'Pancakes',
            'ingredients' => [['quantity' => '1 cup', 'name' => 'Buttermilk', 'note' => null, 'is_optional' => false]],
            'substitutes' => [['instead_of' => 'Sour cream', 'note' => null, 'ingredients' => [['quantity' => '1 cup', 'name' => 'Yogurt']]]],
        ])
        ->call('create')
        ->assertHasFormErrors();

    expect(Recipe::count())->toBe(0);
});

it('reads substitutes into the review step and saves them', function () {
    RecipeReader::fake([readerRecipe([
        'name' => 'Buttermilk pancakes',
        'ingredients' => [['quantity' => '1 cup', 'name' => 'Buttermilk', 'note' => null, 'optional' => false, 'category' => 'dairy_and_eggs']],
        'substitutes' => [['instead_of' => 'Buttermilk', 'use' => [['quantity' => '1 cup', 'name' => 'Milk', 'category' => 'dairy_and_eggs'], ['quantity' => '1 tbsp', 'name' => 'Lemon juice', 'category' => 'produce']], 'note' => null]],
    ])]);

    livewire(ListRecipes::class)
        ->mountAction('quickAddRecipe')
        ->fillForm(['note' => 'Buttermilk pancakes, or use milk with lemon juice'])
        ->goToNextWizardStep()
        ->assertWizardCurrentStep(2)
        ->assertSchemaStateSet(fn (array $state): bool => array_values($state['substitutes'])[0]['instead_of'] === 'Buttermilk')
        ->callMountedAction()
        ->assertHasNoFormErrors();

    expect(Recipe::sole()->substitutes->sole()->describe())->toBe('1 cup Milk + 1 tbsp Lemon juice')
        ->and(Ingredient::query()->named('Lemon juice')->sole()->category)->toBe(IngredientCategory::Produce);
});

it('adds a variant by hand, shows it on the recipe page and logs which one was made', function () {
    // Another recipe's variant first, so this one's id isn't the option's position.
    Recipe::factory()->create()->variants()->create(['name' => 'Blueberry']);

    livewire(CreateRecipe::class)
        ->fillForm([
            'name' => 'Salsa',
            'ingredients' => [
                ['quantity' => '4', 'name' => 'Tomatoes', 'note' => null, 'is_optional' => false],
                ['quantity' => '1', 'name' => 'Jalapeño', 'note' => null, 'is_optional' => false],
            ],
            'variants' => [
                ['name' => 'Hot', 'notes' => 'Keep the seeds in.', 'ingredients' => [
                    ['quantity' => '3', 'name' => 'jalapeño', 'note' => null, 'is_left_out' => false],
                    ['quantity' => '1', 'name' => 'Habanero', 'note' => null, 'is_left_out' => false],
                ]],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $recipe = Recipe::query()->where('name', 'Salsa')->sole();
    $hot = $recipe->variants->sole();

    livewire(ViewRecipe::class, ['record' => $recipe->getRouteKey()])
        ->assertSee('Variant')
        ->assertDontSee('Habanero')
        ->callAction('variant', data: ['variant' => (string) $hot->id])
        ->assertSet('variant', $hot->id)
        ->assertSee('Making it Hot: Keep the seeds in.')
        ->assertSee('Making Hot')
        // After a call the assertion reads the JSON response, where ñ is escaped.
        ->assertSeeInOrder(['Tomatoes', '3', 'Jalape', 'for Hot', 'Habanero'])
        ->mountAction('madeIt')
        ->assertSchemaStateSet(['variant' => 'Hot'])
        ->callMountedAction()
        ->assertHasNoFormErrors();

    expect($recipe->cookLogs()->sole()->madeAs())->toBe('Version 1, Hot');
});
