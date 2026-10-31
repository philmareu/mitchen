<?php

use App\Actions\AddRecipe;
use App\Actions\ReadRecipe;
use App\Ai\Agents\RecipeReader;
use App\Enums\IngredientCategory;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeSubstitute;
use App\Support\RecipeChanges;
use App\Support\RecipeDraft;
use App\Support\RecipeLine;
use App\Support\SubstituteDraft;

/**
 * Buttermilk pancakes: buttermilk can be swapped for milk and lemon juice.
 */
function pancakes(): Recipe
{
    $recipe = app(AddRecipe::class)->handle(new RecipeDraft(
        name: 'Buttermilk pancakes',
        servings: 4,
        ingredients: [
            new RecipeLine('Buttermilk', '1 cup', category: IngredientCategory::DairyAndEggs),
            new RecipeLine('Eggs', '1'),
        ],
        substitutes: [
            new SubstituteDraft('buttermilk', [new RecipeLine('Milk', '1 cup'), new RecipeLine('Lemon juice', '1 tbsp', category: IngredientCategory::Produce)], 'Let it sit 5 minutes.'),
        ],
    ));

    InventoryItem::factory()->for(Ingredient::query()->named('Eggs')->sole())->create(['quantity' => 'a dozen']);

    return $recipe->fresh();
}

function stockUp(string $name, string $quantity = 'plenty'): void
{
    InventoryItem::factory()->for(Ingredient::query()->named($name)->sole())->create(['quantity' => $quantity]);
}

it('keeps substitutes apart from the recipe\'s own lines, linked to the ingredient they stand in for', function () {
    $recipe = pancakes();
    $substitute = $recipe->substitutes->sole();

    expect($recipe->ingredients->pluck('name')->all())->toBe(['Buttermilk', 'Eggs'])
        ->and($substitute)
        ->instead_of->toBe('Buttermilk')
        ->ingredient_id->toBe(Ingredient::query()->named('Buttermilk')->sole()->id)
        ->note->toBe('Let it sit 5 minutes.')
        ->and($substitute->ingredients->pluck('recipe_id')->unique()->all())->toBe([$recipe->id])
        ->and(Ingredient::query()->named('Lemon juice')->sole()->category)->toBe(IngredientCategory::Produce)
        ->and($recipe->ingredients[0]->substitutes()->modelKeys())->toBe([$substitute->id])
        ->and($recipe->ingredients[1]->substitutes())->toBeEmpty()
        ->and($substitute->describe())->toBe('1 cup Milk + 1 tbsp Lemon juice')
        ->and($substitute->describe(2))->toBe('2 cups Milk + 2 tbsp Lemon juice');
});

it('counts an ingredient that is out as there when a substitute is on hand', function () {
    $recipe = pancakes();
    stockUp('Milk');

    expect($recipe->missing())->toBe(['Buttermilk'])
        ->and(Recipe::query()->readyToCook()->exists())->toBeFalse();

    stockUp('Lemon juice', 'low');
    $recipe = Recipe::query()->with(Recipe::KITCHEN)->find($recipe->id);

    expect($recipe->ingredients[0]->isCoveredBySubstitute())->toBeTrue()
        ->and($recipe->substitutes->sole()->availability()->getLabel())->toBe('Running low')
        ->and($recipe->missing())->toBe([])
        ->and(Recipe::query()->readyToCook()->modelKeys())->toBe([$recipe->id]);

    stockUp('Buttermilk');

    expect($recipe->fresh()->ingredients[0]->isCoveredBySubstitute())->toBeFalse();
});

it('reads substitutes from a card, for ingredients the recipe has', function () {
    RecipeReader::fake([readerRecipe([
        'ingredients' => [
            ['quantity' => '1 cup', 'name' => 'Buttermilk', 'note' => null, 'optional' => false, 'category' => 'dairy_and_eggs'],
        ],
        'substitutes' => [
            ['instead_of' => 'buttermilk', 'use' => [['quantity' => '1 cup', 'name' => 'milk', 'category' => 'dairy_and_eggs'], ['quantity' => '1 tbsp', 'name' => 'Lemon juice', 'category' => 'produce']], 'note' => 'Let it sit.'],
            ['instead_of' => 'Sugar', 'use' => [['quantity' => '2 tbsp', 'name' => 'Honey', 'category' => 'baking']], 'note' => null],
            ['instead_of' => 'Buttermilk', 'use' => [], 'note' => null],
        ],
    ])]);
    Ingredient::factory()->create(['name' => 'Milk']);

    $draft = app(ReadRecipe::class)->handle('pancakes');

    expect($draft->substitutes)->toHaveCount(1)
        ->and($draft->substitutes[0]->insteadOf)->toBe('Buttermilk')
        ->and(array_map(fn (RecipeLine $line): string => $line->name, $draft->substitutes[0]->ingredients))->toBe(['Milk', 'Lemon juice'])
        ->and($draft->toArray()['substitutes'][0])->toMatchArray(['instead_of' => 'Buttermilk', 'note' => 'Let it sit.']);
});

it('says when a substitute was added, changed or removed', function () {
    $milk = ['instead_of' => 'Buttermilk', 'note' => null, 'ingredients' => [['quantity' => '1 cup', 'name' => 'Milk'], ['quantity' => '1 tbsp', 'name' => 'Lemon juice']]];
    $yogurt = ['instead_of' => 'Sour cream', 'note' => null, 'ingredients' => [['quantity' => '1 cup', 'name' => 'Plain yogurt']]];
    $vinegar = [...$milk, 'ingredients' => [['quantity' => '1 cup', 'name' => 'Milk'], ['quantity' => '1 tbsp', 'name' => 'White vinegar']]];

    expect(RecipeChanges::between(['substitutes' => [$milk]], ['substitutes' => [$vinegar, $yogurt]]))->toBe([
        'Changed the substitute for Buttermilk: 1 cup Milk + 1 tbsp White vinegar',
        'Added a substitute for Sour cream: 1 cup Plain yogurt',
    ])->and(RecipeChanges::between(['substitutes' => [$milk]], []))->toBe(['Removed the substitute for Buttermilk']);
});

it('drops the substitutes with the recipe', function () {
    $recipe = pancakes();

    $recipe->delete();

    expect(RecipeSubstitute::count())->toBe(0)
        ->and(RecipeIngredient::count())->toBe(0);
});
