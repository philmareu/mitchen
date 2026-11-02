<?php

use App\Actions\AddRecipe;
use App\Enums\EquipmentCategory;
use App\Enums\StorageLocation;
use App\Mcp\Servers\MitchenServer;
use App\Mcp\Tools\AddRecipe as AddRecipeTool;
use App\Mcp\Tools\GetRecipe;
use App\Mcp\Tools\ListEquipment;
use App\Mcp\Tools\LogCook;
use App\Mcp\Tools\WhatToCook;
use App\Models\Equipment;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\Recipe;
use App\Models\ShoppingListItem;
use App\Models\User;
use App\Support\RecipeDraft;
use App\Support\RecipeLine;
use App\Support\SubstituteDraft;
use App\Support\VariantDraft;
use Illuminate\Testing\Fluent\AssertableJson;

beforeEach(function () {
    $this->cook = User::factory()->create();

    $this->recipe = fn (string $name, array $ingredients, array $variants = [], array $equipment = [], array $substitutes = []): Recipe => app(AddRecipe::class)->handle(new RecipeDraft(
        name: $name,
        servings: 4,
        ingredients: array_map(fn (string|RecipeLine $line): RecipeLine => is_string($line) ? new RecipeLine($line) : $line, $ingredients),
        substitutes: $substitutes,
        variants: $variants,
        equipment: $equipment,
    ));

    $this->stock = function (string ...$names): void {
        foreach ($names as $name) {
            InventoryItem::factory()
                ->for(Ingredient::query()->named($name)->first() ?? Ingredient::factory()->create(['name' => $name]))
                ->create(['quantity' => 'a dozen', 'location' => StorageLocation::Fridge]);
        }
    };
});

it('lists recipes closest first, then longest since made', function () {
    ($this->stock)('Eggs', 'Butter', 'Tomatoes', 'Onion');
    ($this->recipe)('Fried eggs', ['Eggs', 'Butter'])->cookLogs()->create(['cooked_on' => '2026-10-20']);
    ($this->recipe)('Scrambled eggs', ['Eggs', 'Butter']);
    ($this->recipe)('Salsa', ['Tomatoes', 'Onion', 'Cilantro'], [new VariantDraft('No cilantro', [new RecipeLine('Cilantro', isLeftOut: true)])]);
    ($this->recipe)('Crepes', ['Eggs', 'Flour', 'Milk']);
    ShoppingListItem::factory()->create(['name' => 'Milk']);
    ($this->recipe)('Waffles', ['Eggs'], equipment: ['Waffle iron']);

    MitchenServer::actingAs($this->cook)
        ->tool(WhatToCook::class)
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('recipes', fn ($recipes) => collect($recipes)->map(fn (array $recipe): array => [$recipe['name'], $recipe['readiness'], $recipe['made_as'], $recipe['missing'], $recipe['to_buy']])->all() === [
                ['Salsa', 'ready', 'No cilantro', null, []],
                ['Scrambled eggs', 'ready', null, null, []],
                ['Fried eggs', 'ready', null, null, []],
                ['Crepes', 'quick_shop', null, 'Flour, Milk', ['Flour']],
                ['Waffles', 'needs_equipment', null, 'Waffle iron', []],
            ])
            ->where('recipes.2.last_made', '2026-10-20'));

    MitchenServer::actingAs($this->cook)
        ->tool(WhatToCook::class, ['readiness' => ['quick_shop', 'needs_equipment']])
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('recipes', fn ($recipes) => collect($recipes)->pluck('name')->all() === ['Crepes', 'Waffles']));
});

it('gives a recipe as a variant and scaled, with what\'s on hand for each line', function () {
    $recipe = ($this->recipe)(
        'Buttermilk pancakes',
        [new RecipeLine('Buttermilk', '1 cup'), new RecipeLine('Eggs', '1'), new RecipeLine('Salt', 'a pinch')],
        variants: [new VariantDraft('Blueberry', [new RecipeLine('Blueberries', '1 cup')])],
        equipment: ['Griddle'],
        substitutes: [new SubstituteDraft('Buttermilk', [new RecipeLine('Milk', '1 cup'), new RecipeLine('Lemon juice', '1 tbsp')])],
    );
    ($this->stock)('Eggs', 'Salt', 'Milk', 'Lemon juice');
    $log = $recipe->cookLogs()->create(['cooked_on' => '2026-10-25', 'thoughts' => 'Fluffy.', 'next_time' => 'More blueberries.']);

    MitchenServer::actingAs($this->cook)
        ->tool(GetRecipe::class, ['name' => 'pancake', 'variant' => 'blueberry', 'scale' => 2])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('id', $recipe->id)
            ->where('url', url("/recipes/{$recipe->id}"))
            ->where('made_as', 'Blueberry')
            ->where('readiness', 'needs_equipment')
            ->where('missing', 'Griddle, Blueberries')
            ->where('ingredients', fn ($lines) => collect($lines)->map(fn (array $line): array => [$line['name'], $line['quantity'], $line['on_hand'], $line['where'], $line['substitute_on_hand']])->all() === [
                ['Buttermilk', '2 cups', 'out', null, '2 cups Milk + 2 tbsp Lemon juice'],
                ['Eggs', '2', 'on_hand', '12 · Fridge', null],
                ['Salt', 'a pinch', 'on_hand', '12 · Fridge', null],
                ['Blueberries', '2 cups', 'out', null, null],
            ])
            ->where('substitutes.0.instead_of', 'Buttermilk')
            ->where('variants.0.name', 'Blueberry')
            ->where('equipment', [['name' => 'Griddle', 'in_kitchen' => false]])
            ->where('cook_log', [['id' => $log->id, 'cooked_on' => '2026-10-25', 'variant' => null, 'thoughts' => 'Fluffy.', 'next_time' => 'More blueberries.']])
            ->etc());
});

it('says which recipes or variants there are when the name doesn\'t pick one', function () {
    ($this->recipe)('Pancakes', ['Eggs'], [new VariantDraft('Blueberry', [new RecipeLine('Blueberries')])]);
    ($this->recipe)('Potato pancakes', ['Potatoes']);

    MitchenServer::actingAs($this->cook)
        ->tool(GetRecipe::class, ['name' => 'pan'])
        ->assertHasErrors(['Several recipes match: Pancakes (id 1), Potato pancakes (id 2).']);

    MitchenServer::actingAs($this->cook)
        ->tool(GetRecipe::class, ['name' => 'pancakes', 'variant' => 'chocolate chip'])
        ->assertHasErrors(['Pancakes has these variants: Blueberry.']);

    MitchenServer::actingAs($this->cook)
        ->tool(GetRecipe::class, ['name' => 'lasagna'])
        ->assertHasErrors(['No recipe matches that.']);
});

it('lists the equipment, by name or kind', function () {
    Equipment::factory()->create(['name' => 'Cast iron skillet', 'category' => EquipmentCategory::Cookware, 'brand' => 'Lodge', 'size' => '10 in', 'count' => 1]);
    Equipment::factory()->create(['name' => 'Half sheet pan', 'category' => EquipmentCategory::Bakeware, 'count' => 2]);
    Equipment::factory()->create(['name' => 'Ladle', 'category' => EquipmentCategory::Utensils]);

    MitchenServer::actingAs($this->cook)
        ->tool(ListEquipment::class, ['search' => 'pan'])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('equipment', fn ($pieces) => collect($pieces)->pluck('name')->all() === ['Half sheet pan'])
            ->etc());

    MitchenServer::actingAs($this->cook)
        ->tool(ListEquipment::class, ['category' => 'cookware'])
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->has('equipment', 1)
            ->has('equipment.0', fn (AssertableJson $skillet) => $skillet
                ->where('name', 'Cast iron skillet')
                ->where('brand', 'Lodge')
                ->where('size', '10 in')
                ->where('count', 1)
                ->etc())
            ->etc());
});

it('saves a recipe in the catalog\'s names, adding what\'s new', function () {
    Ingredient::factory()->create(['name' => 'All-purpose flour']);
    ($this->stock)('All-purpose flour', 'Milk', 'Lemon juice');
    Equipment::factory()->create(['name' => 'Cast iron griddle']);

    MitchenServer::actingAs($this->cook)
        ->tool(AddRecipeTool::class, [
            'name' => 'Buttermilk pancakes',
            'source' => 'https://example.com/pancakes',
            'servings' => 4,
            'ingredients' => [
                ['name' => 'all-purpose flour', 'quantity' => '2 cups'],
                ['name' => 'Buttermilk', 'quantity' => '2 cups', 'category' => 'dairy_and_eggs'],
                ['name' => 'Maple syrup', 'note' => 'warm', 'optional' => true, 'category' => 'oils_and_condiments'],
            ],
            'substitutes' => [
                ['instead_of' => 'Buttermilk', 'use' => [['name' => 'Milk', 'quantity' => '2 cups'], ['name' => 'Lemon juice', 'quantity' => '2 tbsp']], 'note' => 'Let it sit 5 minutes.'],
            ],
            'variants' => [
                ['name' => 'Blueberry', 'changes' => [['name' => 'Blueberries', 'quantity' => '1 cup', 'category' => 'produce']]],
            ],
            'equipment' => ['cast iron griddle', 'Stand mixer'],
            'steps' => ['Whisk the dry ingredients.', 'Fold in the buttermilk.'],
        ])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('name', 'Buttermilk pancakes')
            ->where('url', url('/recipes/'.Recipe::query()->sole()->id))
            ->where('new_ingredients', ['Blueberries', 'Buttermilk', 'Maple syrup'])
            ->where('equipment_not_in_kitchen', ['Stand mixer'])
            ->where('readiness', 'needs_equipment')
            ->etc());

    $recipe = Recipe::query()->with(['ingredients', 'substitutes.ingredients', 'variants.ingredients', 'equipment'])->sole();
    expect($recipe)
        ->source->toBe('https://example.com/pancakes')
        ->servings->toBe(4)
        ->steps->toBe(['Whisk the dry ingredients.', 'Fold in the buttermilk.']);
    expect($recipe->ingredients->map(fn ($line) => [$line->name, $line->quantity, $line->is_optional])->all())->toBe([
        ['All-purpose flour', '2 cups', false],
        ['Buttermilk', '2 cups', false],
        ['Maple syrup', null, true],
    ]);
    expect($recipe->substitutes->sole()->describe())->toBe('2 cups Milk + 2 tbsp Lemon juice');
    expect($recipe->variants->sole()->ingredients->pluck('name')->all())->toBe(['Blueberries']);
    expect($recipe->equipment->pluck('name')->all())->toBe(['Cast iron griddle', 'Stand mixer']);
    expect(Ingredient::query()->named('Buttermilk')->sole()->category->value)->toBe('dairy_and_eggs');
});

it('saves nothing when a recipe can\'t be saved as given', function (array $recipe, string $error) {
    ($this->recipe)('Pancakes', ['Eggs']);

    MitchenServer::actingAs($this->cook)
        ->tool(AddRecipeTool::class, $recipe)
        ->assertHasErrors([$error]);

    expect(Recipe::query()->pluck('name')->all())->toBe(['Pancakes']);
})->with([
    'same name' => [
        ['name' => 'pancakes', 'ingredients' => [['name' => 'Flour']]],
        'There\'s already a recipe called Pancakes (id 1).',
    ],
    'nothing in it' => [
        ['name' => 'Waffles'],
        'A recipe needs ingredients or steps.',
    ],
    'a substitute for something it doesn\'t have' => [
        ['name' => 'Waffles', 'ingredients' => [['name' => 'Flour']], 'substitutes' => [['instead_of' => 'Buttermilk', 'use' => [['name' => 'Milk']]]]],
        'Each substitute\'s instead_of must name one of the recipe\'s ingredients',
    ],
]);

it('logs that the cook made a recipe', function () {
    $this->travelTo('2026-11-02');
    $recipe = ($this->recipe)('Salsa', ['Tomatoes'], [new VariantDraft('Hot', [new RecipeLine('Habanero', '2')])]);
    $recipe->forceFill(['version' => 3])->save();

    MitchenServer::actingAs($this->cook)
        ->tool(LogCook::class, ['recipe_id' => $recipe->id, 'variant' => 'hot', 'thoughts' => 'Too sharp.', 'next_time' => 'Less vinegar.'])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('cook_log.cooked_on', '2026-11-02')
            ->where('cook_log.version', 3)
            ->where('cook_log.variant', 'Hot')
            ->where('waiting_notes', ['Less vinegar.'])
            ->etc());

    expect($recipe->cookLogs()->sole())
        ->thoughts->toBe('Too sharp.')
        ->next_time->toBe('Less vinegar.');
});

it('adds to an earlier cook log entry, changing only what it\'s given', function () {
    $recipe = ($this->recipe)('Pickled onions', ['Red onion']);
    $log = $recipe->cookLogs()->create(['cooked_on' => '2026-10-28', 'thoughts' => 'Too early to tell.']);

    MitchenServer::actingAs($this->cook)
        ->tool(LogCook::class, ['recipe_id' => $recipe->id, 'cook_log_id' => $log->id, 'next_time' => 'More sugar.'])
        ->assertOk();

    expect($log->fresh())
        ->cooked_on->toDateString()->toBe('2026-10-28')
        ->thoughts->toBe('Too early to tell.')
        ->next_time->toBe('More sugar.');
    expect($recipe->cookLogs()->count())->toBe(1);
});

it('logs nothing for a variant the recipe hasn\'t got', function () {
    $recipe = ($this->recipe)('Salsa', ['Tomatoes'], [new VariantDraft('Hot', [new RecipeLine('Habanero', '2')])]);

    MitchenServer::actingAs($this->cook)
        ->tool(LogCook::class, ['recipe_id' => $recipe->id, 'variant' => 'Mild'])
        ->assertHasErrors(['Salsa has these variants: Hot.']);

    expect($recipe->cookLogs()->exists())->toBeFalse();
});
