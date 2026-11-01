<?php

use App\Actions\AddRecipe;
use App\Enums\Readiness;
use App\Enums\Sourcing;
use App\Models\Equipment;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\ShoppingListItem;
use App\Support\RecipeDraft;
use App\Support\RecipeLine;
use App\Support\RecipeShortfall;
use App\Support\VariantDraft;

/**
 * A recipe with these ingredients and equipment; nothing is on hand unless stocked.
 *
 * @param  list<string>  $ingredients
 * @param  list<string>  $equipment
 */
function suggestable(string $name, array $ingredients, array $equipment = []): Recipe
{
    $recipe = Recipe::factory()->create(['name' => $name]);

    foreach ($ingredients as $sort => $ingredient) {
        $recipe->ingredients()->create(['name' => $ingredient, 'sort' => $sort]);
    }

    foreach ($equipment as $piece) {
        $recipe->equipment()->create(['name' => $piece]);
    }

    return $recipe;
}

function onHand(string ...$names): void
{
    foreach ($names as $name) {
        $ingredient = Ingredient::query()->named($name)->first() ?? Ingredient::factory()->create(['name' => $name]);

        InventoryItem::factory()->for($ingredient)->create(['quantity' => 'plenty']);
    }
}

function closest(Recipe $recipe): RecipeShortfall
{
    return Recipe::query()->with(Recipe::KITCHEN)->findOrFail($recipe->id)->closestShortfall();
}

it('sorts recipes by how close the kitchen is to making them', function () {
    onHand('Eggs', 'Butter', 'Flour', 'Milk');
    Equipment::factory()->create(['name' => 'Skillet']);

    $ready = suggestable('Fried eggs', ['Eggs', 'Butter'], ['Skillet']);
    $quick = suggestable('Crepes', ['Eggs', 'Flour', 'Milk', 'Lemon', 'Sugar', 'Vanilla']);
    RecipeIngredient::factory()->optional()->for($quick)->create(['name' => 'Powdered sugar']);
    $big = suggestable('Cake', ['Eggs', 'Sugar', 'Vanilla', 'Cocoa', 'Buttermilk']);
    $waffles = suggestable('Waffles', ['Eggs'], ['Waffle iron']);

    expect(closest($ready))->readiness()->toBe(Readiness::Ready)->summary()->toBeNull()
        ->and(closest($quick))->readiness()->toBe(Readiness::QuickShop)->summary()->toBe('Lemon, Sugar, Vanilla')
        ->and(closest($big))->readiness()->toBe(Readiness::BigShop)
        ->and(closest($waffles))->readiness()->toBe(Readiness::NeedsEquipment)->summary()->toBe('Waffle iron')
        ->and(Recipe::query()->readyToCook()->pluck('name')->all())->toBe(['Fried eggs'])
        ->and(Recipe::query()->whereReadiness(Readiness::QuickShop, Readiness::BigShop)->orderBy('name')->pluck('name')->all())->toBe(['Cake', 'Crepes'])
        ->and(Recipe::query()->readinessByKey()->all())->toBe([
            $ready->id => Readiness::Ready,
            $quick->id => Readiness::QuickShop,
            $big->id => Readiness::BigShop,
            $waffles->id => Readiness::NeedsEquipment,
        ]);
});

it('says to plan ahead for what takes time to get, whatever else is missing', function () {
    Ingredient::factory()->makeAhead(3)->create(['name' => 'Pickled red onions']);
    Ingredient::factory()->hardToFind()->create(['name' => 'Gochugaru']);
    $tacos = suggestable('Fish tacos', ['Pickled red onions', 'Cod', 'Tortillas', 'Limes', 'Cabbage', 'Gochugaru']);
    ShoppingListItem::create(['name' => 'limes']);

    $shortfall = closest($tacos);

    expect($shortfall)
        ->readiness()->toBe(Readiness::PlanAhead)
        ->leadDays()->toBe(3)
        ->summary()->toBe('Pickled red onions (make ahead, about 3 days), Cod, Tortillas, Limes, Cabbage, Gochugaru (hard to find)')
        ->and($shortfall->needingLeadTime()->pluck('name')->all())->toBe(['Pickled red onions', 'Gochugaru'])
        ->and($shortfall->toBuy()->pluck('name')->all())->toBe(['Cod', 'Tortillas', 'Cabbage', 'Gochugaru']);

    onHand('Pickled red onions', 'Gochugaru');

    expect(closest($tacos)->readiness())->toBe(Readiness::BigShop);
});

it('plans ahead for a seasonal ingredient only out of season', function () {
    Ingredient::factory()->seasonal([6, 7, 8])->create(['name' => 'Peaches']);
    $cobbler = suggestable('Peach cobbler', ['Peaches']);

    $this->travelTo('2026-07-15');

    expect(closest($cobbler))->readiness()->toBe(Readiness::QuickShop)->summary()->toBe('Peaches');

    $this->travelTo('2026-10-30');

    expect(closest($cobbler))->readiness()->toBe(Readiness::PlanAhead)->summary()->toBe('Peaches (in season Jun–Aug)');
});

it('makes it as a variant only when that is closer than the recipe as written', function () {
    $salsa = app(AddRecipe::class)->handle(new RecipeDraft(
        name: 'Salsa',
        ingredients: [new RecipeLine('Tomatoes', '4'), new RecipeLine('Jalapeño', '1'), new RecipeLine('Cilantro', '¼ cup'), new RecipeLine('Lime', '1')],
        variants: [
            new VariantDraft('Hot', [new RecipeLine('Habanero', '1')]),
            new VariantDraft('No cilantro', [new RecipeLine('Cilantro', isLeftOut: true)]),
        ],
    ));
    onHand('Tomatoes', 'Jalapeño');

    // Leaving the cilantro out still needs a lime: no closer.
    expect(closest($salsa))->readiness()->toBe(Readiness::QuickShop)->variant->toBeNull();

    onHand('Lime');

    expect(closest($salsa))->readiness()->toBe(Readiness::Ready)->summary()->toBe('As No cilantro')
        ->and(Recipe::query()->readyToCook()->modelKeys())->toBe([$salsa->id]);

    onHand('Cilantro');

    expect(closest($salsa))->readiness()->toBe(Readiness::Ready)->variant->toBeNull();
});

it('says what getting more of an ingredient takes', function (array $state, ?string $leadTime) {
    expect(Ingredient::factory()->make($state)->leadTime())->toBe($leadTime);
})->with([
    'any store' => [['sourcing' => Sourcing::Grocery, 'lead_days' => 3], null],
    'hard to find' => [['sourcing' => Sourcing::HardToFind], 'hard to find'],
    'a day' => [['sourcing' => Sourcing::HardToFind, 'lead_days' => 1], 'hard to find, about a day'],
    'days' => [['sourcing' => Sourcing::MakeAhead, 'lead_days' => 3], 'make ahead, about 3 days'],
    'a week' => [['sourcing' => Sourcing::MakeAhead, 'lead_days' => 7], 'make ahead, about a week'],
    'weeks' => [['sourcing' => Sourcing::MakeAhead, 'lead_days' => 21], 'make ahead, about 3 weeks'],
    'a season, as the form sends it' => [['sourcing' => Sourcing::Seasonal, 'season' => ['10', '6', '7', '8']], 'in season Jun–Aug, Oct'],
    'over the new year' => [['sourcing' => Sourcing::Seasonal, 'season' => [1, 2, 12]], 'in season Dec–Feb'],
    'all year' => [['sourcing' => Sourcing::Seasonal, 'season' => range(1, 12)], 'in season all year'],
    'no months' => [['sourcing' => Sourcing::Seasonal], 'seasonal'],
]);

it('forgets the days and months that no longer apply', function () {
    $ingredient = Ingredient::factory()->seasonal([6, 7])->create();

    expect($ingredient->fresh()->season)->toBe([6, 7]);

    $ingredient->update(['sourcing' => Sourcing::MakeAhead, 'lead_days' => 3]);

    expect($ingredient->fresh())->season->toBeNull()->lead_days->toBe(3);

    $ingredient->update(['sourcing' => Sourcing::Grocery]);

    expect($ingredient->fresh())->lead_days->toBeNull()->needsLeadTime()->toBeFalse();
});
