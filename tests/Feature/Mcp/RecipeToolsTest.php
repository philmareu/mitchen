<?php

use App\Actions\AddRecipe;
use App\Enums\EquipmentCategory;
use App\Enums\StorageLocation;
use App\Mcp\Servers\MitchenServer;
use App\Mcp\Tools\GetRecipe;
use App\Mcp\Tools\ListEquipment;
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
    $recipe->cookLogs()->create(['cooked_on' => '2026-10-25', 'thoughts' => 'Fluffy.', 'next_time' => 'More blueberries.']);

    MitchenServer::actingAs($this->cook)
        ->tool(GetRecipe::class, ['name' => 'pancake', 'variant' => 'blueberry', 'scale' => 2])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('id', $recipe->id)
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
            ->where('cook_log', [['cooked_on' => '2026-10-25', 'variant' => null, 'thoughts' => 'Fluffy.', 'next_time' => 'More blueberries.']])
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
            ->where('equipment', fn ($pieces) => collect($pieces)->pluck('name')->all() === ['Half sheet pan']));

    MitchenServer::actingAs($this->cook)
        ->tool(ListEquipment::class, ['category' => 'cookware'])
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->has('equipment', 1)
            ->has('equipment.0', fn (AssertableJson $skillet) => $skillet
                ->where('name', 'Cast iron skillet')
                ->where('brand', 'Lodge')
                ->where('size', '10 in')
                ->where('count', 1)
                ->etc()));
});
