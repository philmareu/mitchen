<?php

use App\Actions\AddRecipe;
use App\Actions\AddToShoppingList;
use App\Actions\ReadRecipe;
use App\Actions\ReviseRecipe;
use App\Ai\Agents\RecipeReader;
use App\Ai\Agents\RecipeReviser;
use App\Enums\IngredientCategory;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\ShoppingListItem;
use App\Support\RecipeChanges;
use App\Support\RecipeDraft;
use App\Support\RecipeLine;
use App\Support\VariantDraft;
use Laravel\Ai\Prompts\AgentPrompt;

/**
 * Salsa as written, Hot (more jalapeño and a habanero) and No cilantro.
 */
function salsa(): Recipe
{
    return app(AddRecipe::class)->handle(new RecipeDraft(
        name: 'Salsa',
        servings: 4,
        ingredients: [
            new RecipeLine('Tomatoes', '4', category: IngredientCategory::Produce),
            new RecipeLine('Jalapeño', '1', 'seeded', category: IngredientCategory::Produce),
            new RecipeLine('Cilantro', '¼ cup', category: IngredientCategory::Produce),
        ],
        variants: [
            new VariantDraft('Hot', [new RecipeLine('jalapeño', '3'), new RecipeLine('Habanero', '1', category: IngredientCategory::Produce)], 'Keep the seeds in.'),
            new VariantDraft('No cilantro', [new RecipeLine('Cilantro', isLeftOut: true)]),
        ],
    ))->load(Recipe::KITCHEN);
}

/**
 * @return list<string>
 */
function lineNames(iterable $lines): array
{
    return collect($lines)->map(fn (RecipeIngredient $line): string => trim($line->quantity.' '.$line->name))->all();
}

it('makes a variant from the recipe\'s lines with its changes', function () {
    $recipe = salsa();
    [$hot, $noCilantro] = $recipe->variants;

    expect(lineNames($recipe->ingredients))->toBe(['4 Tomatoes', '1 Jalapeño', '¼ cup Cilantro'])
        ->and(lineNames($recipe->linesFor(null)))->toBe(['4 Tomatoes', '1 Jalapeño', '¼ cup Cilantro'])
        ->and(lineNames($recipe->linesFor($hot)))->toBe(['4 Tomatoes', '3 Jalapeño', '¼ cup Cilantro', '1 Habanero'])
        ->and(lineNames($recipe->linesFor($noCilantro)))->toBe(['4 Tomatoes', '1 Jalapeño'])
        ->and($hot->notes)->toBe('Keep the seeds in.')
        ->and($hot->ingredients->pluck('recipe_id')->unique()->all())->toBe([$recipe->id]);
});

it('says what is missing and what to buy for the variant being made', function () {
    foreach (['Tomatoes', 'Jalapeño', 'Cilantro'] as $name) {
        InventoryItem::factory()->for(Ingredient::factory()->create(['name' => $name]))->create(['quantity' => 'plenty']);
    }
    $recipe = salsa();
    [$hot] = $recipe->variants;

    expect($recipe->missing())->toBe([])
        ->and($recipe->ingredientsToBuy())->toBeEmpty()
        ->and($recipe->ingredientsToBuy($hot)->pluck('name')->all())->toBe(['Habanero']);

    $habanero = $recipe->ingredientsToBuy($hot)->sole();

    expect(app(AddToShoppingList::class)->fromRecipe($recipe, [$habanero->id], 2, $hot))->toBe(['Habanero'])
        ->and(ShoppingListItem::sole()->quantity)->toBe('2');
});

it('reads variants from a card', function () {
    RecipeReader::fake([readerRecipe([
        'ingredients' => [['quantity' => '1', 'name' => 'Jalapeño', 'note' => null, 'optional' => false, 'category' => 'produce']],
        'variants' => [
            ['name' => 'hot', 'notes' => 'Keep the seeds.', 'changes' => [
                ['quantity' => '3', 'name' => 'jalapeño', 'note' => 'seeds in', 'optional' => false, 'leave_out' => false, 'category' => 'produce'],
                ['quantity' => null, 'name' => 'Cilantro', 'note' => null, 'optional' => false, 'leave_out' => true, 'category' => 'produce'],
            ]],
            ['name' => '', 'notes' => null, 'changes' => []],
        ],
    ])]);

    $draft = app(ReadRecipe::class)->handle('salsa');

    expect($draft->variants)->toHaveCount(1)
        ->and($draft->variants[0]->name)->toBe('Hot')
        ->and($draft->variants[0]->ingredients[0])->name->toBe('Jalapeño')->note->toBe('seeds in')
        ->and($draft->variants[0]->ingredients[1]->isLeftOut)->toBeTrue()
        ->and($draft->toArray()['variants'][0]['ingredients'][1])->toMatchArray(['name' => 'Cilantro', 'is_left_out' => true]);
});

it('keeps a variant line\'s note and optional flag through a revision', function () {
    $recipe = salsa();
    $recipe->variants->first()->ingredients->firstWhere('name', 'Habanero')->update(['note' => 'seeded', 'is_optional' => true]);

    RecipeReviser::fake([readerRecipe([
        'name' => 'Salsa',
        'ingredients' => [['quantity' => '1', 'name' => 'Jalapeño', 'note' => 'seeded', 'optional' => false, 'category' => 'produce']],
        'variants' => [
            ['name' => 'Hot', 'notes' => null, 'changes' => [
                ['quantity' => '1', 'name' => 'Habanero', 'note' => 'seeded', 'optional' => true, 'leave_out' => false, 'category' => 'produce'],
            ]],
        ],
        'summary' => 'No changes.',
    ])]);

    $draft = app(ReviseRecipe::class)->handle($recipe->fresh(), []);

    RecipeReviser::assertPrompted(fn (AgentPrompt $prompt): bool => preg_match('/"name": "Habanero",\s+"note": "seeded",\s+"optional": true,/', $prompt->prompt) === 1);

    expect($draft->variants[0]->ingredients[0])
        ->note->toBe('seeded')
        ->isOptional->toBeTrue();
});

it('says when a variant was added, changed or removed', function () {
    $hot = ['name' => 'Hot', 'notes' => null, 'ingredients' => [['quantity' => '3', 'name' => 'Jalapeño', 'is_left_out' => false]]];
    $hotter = [...$hot, 'ingredients' => [['quantity' => '4', 'name' => 'Jalapeño', 'is_left_out' => false]]];
    $plain = ['name' => 'Plain', 'notes' => 'For kids.', 'ingredients' => [['quantity' => null, 'name' => 'Jalapeño', 'is_left_out' => true]]];

    expect(RecipeChanges::between(['variants' => [$hot]], ['variants' => [$hotter, $plain]]))->toBe([
        'Changed the Hot variant: 4 Jalapeño',
        'Added the Plain variant: no Jalapeño (For kids.)',
    ])->and(RecipeChanges::between(['variants' => [$hot]], []))->toBe(['Removed the Hot variant']);
});
