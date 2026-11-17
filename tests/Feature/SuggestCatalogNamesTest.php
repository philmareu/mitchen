<?php

use App\Actions\SuggestCatalogNames;
use App\Ai\Agents\NameMatcher;
use App\Models\Equipment;
use App\Models\Ingredient;
use Laravel\Ai\Prompts\AgentPrompt;

beforeEach(function () {
    config(['ai.providers.anthropic.key' => 'test-key']);
    Ingredient::factory()->create(['name' => 'Green onions']);
    Ingredient::factory()->create(['name' => 'Limes']);
});

it('asks about the names new to the catalog and keeps only suggestions of catalog names', function () {
    NameMatcher::fake([['matches' => [
        ['name' => 'scallions', 'catalog_name' => 'green onions'],
        ['name' => 'Coriander', 'catalog_name' => 'Cilantro'],
        ['name' => 'Saffron', 'catalog_name' => null],
        ['name' => 'Paprika', 'catalog_name' => 'Limes'],
    ]]]);

    $suggestions = app(SuggestCatalogNames::class)->handle(['Scallions', 'Lime', 'Coriander', 'Saffron', 'scallions', '']);

    NameMatcher::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains("- Scallions\n- Coriander\n- Saffron\n")
        && ! $prompt->contains('- Lime')
        && $prompt->contains('The catalog: Green onions, Limes'));

    expect($suggestions)->toBe(['scallions' => 'Green onions']);
});

it('asks nothing when every name is in the catalog', function () {
    NameMatcher::fake()->preventStrayPrompts();

    expect(app(SuggestCatalogNames::class)->handle(['Lime', 'green onion']))->toBe([]);

    NameMatcher::assertNeverPrompted();
});

it('suggests nothing when the assistant can\'t be reached', function () {
    NameMatcher::fake(fn () => throw new RuntimeException('Overloaded'));

    expect(app(SuggestCatalogNames::class)->handle(['Scallions']))->toBe([]);
});

it('suggests the listed piece a name for equipment may mean', function () {
    Equipment::factory()->create(['name' => 'Vitamix']);
    NameMatcher::fake([['matches' => [
        ['name' => 'Blender', 'catalog_name' => 'Vitamix'],
        ['name' => 'Ricer', 'catalog_name' => 'Food mill'],
    ]]]);

    expect(app(SuggestCatalogNames::class)->equipment(['Blender', 'vitamix', 'Ricer']))->toBe(['blender' => 'Vitamix']);

    NameMatcher::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains("- Blender\n- Ricer\n\nThe kitchen's equipment: Vitamix"));
});
