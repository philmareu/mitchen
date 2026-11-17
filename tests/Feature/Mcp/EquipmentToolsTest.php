<?php

use App\Enums\EquipmentCategory;
use App\Mcp\Servers\MitchenServer;
use App\Mcp\Tools\AddEquipment;
use App\Mcp\Tools\ListEquipment;
use App\Models\Equipment;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Testing\Fluent\AssertableJson;

beforeEach(function () {
    $this->cook = User::factory()->create();
});

it('lists what the recipes call for that the kitchen does not have', function () {
    Equipment::factory()->create(['name' => 'Stand mixer', 'category' => EquipmentCategory::Appliances]);
    Recipe::factory()->create(['name' => 'Salsa'])->equipment()->create(['name' => 'Blender']);
    Recipe::factory()->create(['name' => 'Guacamole'])->equipment()->createMany([['name' => 'Molcajete'], ['name' => 'blender'], ['name' => 'Stand mixer']]);

    MitchenServer::actingAs($this->cook)
        ->tool(ListEquipment::class)
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->has('equipment', 1)
            ->where('not_in_kitchen', [
                ['name' => 'Blender', 'recipes' => ['Guacamole', 'Salsa']],
                ['name' => 'Molcajete', 'recipes' => ['Guacamole']],
            ]));

    MitchenServer::actingAs($this->cook)
        ->tool(ListEquipment::class, ['search' => 'BLEND'])
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->has('equipment', 0)
            ->where('not_in_kitchen', [['name' => 'Blender', 'recipes' => ['Guacamole', 'Salsa']]]));

    MitchenServer::actingAs($this->cook)
        ->tool(ListEquipment::class, ['category' => 'appliances'])
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->has('equipment', 1)
            ->where('not_in_kitchen', []));
});

it('adds equipment, and the recipes that call for it then have it', function () {
    $blender = Recipe::factory()->create(['name' => 'Salsa'])->equipment()->create(['name' => 'Blender']);

    MitchenServer::actingAs($this->cook)
        ->tool(AddEquipment::class, ['pieces' => [
            ['name' => 'blender', 'category' => 'appliances', 'brand' => ' Vitamix 5200 ', 'size' => '64 oz', 'notes' => 'Under the counter'],
            ['name' => 'Molcajete'],
        ]])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('equipment', [
                ['name' => 'Blender', 'category' => 'appliances', 'brand' => 'Vitamix 5200', 'size' => '64 oz', 'count' => 1, 'notes' => 'Under the counter', 'added' => true, 'recipes' => ['Salsa']],
                ['name' => 'Molcajete', 'category' => 'other', 'brand' => null, 'size' => null, 'count' => 1, 'notes' => null, 'added' => true, 'recipes' => []],
            ]));

    expect(Equipment::query()->orderBy('name')->pluck('brand', 'name')->all())->toBe(['Blender' => 'Vitamix 5200', 'Molcajete' => null])
        ->and($blender->fresh()->isInKitchen())->toBeTrue();
});

it('fills in the details given for a piece already listed instead of adding it again', function () {
    $blender = Equipment::factory()->create(['name' => 'Blender', 'category' => EquipmentCategory::Appliances, 'brand' => 'Vitamix', 'size' => '64 oz', 'count' => 1, 'notes' => 'Under the counter']);

    MitchenServer::actingAs($this->cook)
        ->tool(AddEquipment::class, ['pieces' => [['name' => 'BLENDER', 'brand' => 'Vitamix 5200', 'size' => '', 'count' => 2]]])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('equipment', [
                ['name' => 'Blender', 'category' => 'appliances', 'brand' => 'Vitamix 5200', 'size' => '64 oz', 'count' => 2, 'notes' => 'Under the counter', 'added' => false, 'recipes' => []],
            ]));

    expect(Equipment::query()->sole())
        ->is($blender)->toBeTrue()
        ->brand->toBe('Vitamix 5200')
        ->size->toBe('64 oz')
        ->count->toBe(2);
});

it('saves none of the equipment when a piece is of an unknown kind', function () {
    MitchenServer::actingAs($this->cook)
        ->tool(AddEquipment::class, ['pieces' => [
            ['name' => 'Blender', 'category' => 'appliances'],
            ['name' => 'Spork', 'category' => 'gadgets'],
        ]])
        ->assertHasErrors(['The selected pieces.1.category is invalid.']);

    expect(Equipment::count())->toBe(0);
});

it('asks before adding a piece whose name is close to a listed one or to one the recipes call for', function () {
    $skillet = Equipment::factory()->create(['name' => 'Cast iron skillet', 'brand' => null]);
    $loaf = Recipe::factory()->create(['name' => 'Banana bread'])->equipment()->create(['name' => 'Loaf pan']);

    MitchenServer::actingAs($this->cook)
        ->tool(AddEquipment::class, ['pieces' => [['name' => 'Skillet'], ['name' => 'Bread pan'], ['name' => 'Molcajete']]])
        ->assertHasErrors(['Skillet (the kitchen has Cast iron skillet); Bread pan (recipes call for Loaf pan)']);

    expect(Equipment::count())->toBe(1);

    MitchenServer::actingAs($this->cook)
        ->tool(AddEquipment::class, ['pieces' => [['name' => 'Cast iron skillets', 'brand' => 'Lodge'], ['name' => 'loaf pans'], ['name' => 'Molcajete'], ['name' => 'Skillet']], 'new_equipment' => ['skillet']])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('equipment.0.name', 'Cast iron skillet')
            ->where('equipment.0.added', false)
            ->where('equipment.1.name', 'Loaf pan')
            ->where('equipment.1.recipes', ['Banana bread'])
            ->etc());

    expect($skillet->fresh()->brand)->toBe('Lodge')
        ->and(Equipment::query()->orderBy('name')->pluck('name')->all())->toBe(['Cast iron skillet', 'Loaf pan', 'Molcajete', 'Skillet'])
        ->and($loaf->fresh()->isInKitchen())->toBeTrue();
});
