<?php

namespace App\Mcp\Tools;

use App\Enums\EquipmentCategory;
use App\Mcp\Tools\Concerns\PresentsKitchen;
use App\Models\Equipment;
use App\Models\RecipeEquipment;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('The kitchen\'s equipment, from appliances down to utensils: each piece\'s brand and model, size, how many there are, and the cook\'s notes (where it lives, how to care for it). Also not_in_kitchen: equipment the recipes call for that the kitchen doesn\'t have, with the recipes, for add-equipment to use the same names.')]
#[IsReadOnly]
class ListEquipment extends Tool
{
    use PresentsKitchen;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): ResponseFactory
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', Rule::enum(EquipmentCategory::class)],
        ]);

        $equipment = Equipment::query()
            ->when($validated['search'] ?? null, fn ($query, string $search) => $query->where('name', 'like', '%'.trim($search).'%'))
            ->when($validated['category'] ?? null, fn ($query, string $category) => $query->where('category', $category))
            ->orderBy('name')
            ->get();

        $search = mb_strtolower(trim($validated['search'] ?? ''));

        $notInKitchen = isset($validated['category']) ? collect() : RecipeEquipment::query()
            ->missingFromKitchen()
            ->filter(fn (array $recipes, string $name): bool => str_contains(mb_strtolower($name), $search))
            ->map(fn (array $recipes, string $name): array => ['name' => $name, 'recipes' => $recipes])
            ->values();

        return Response::structured([
            'equipment' => $equipment->map($this->presentEquipment(...))->all(),
            'not_in_kitchen' => $notInKitchen->all(),
        ]);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()
                ->description('Only pieces whose name contains this, such as "pan" (in not_in_kitchen too).'),
            'category' => $schema->string()
                ->enum(EquipmentCategory::class)
                ->description('Only pieces of this kind. Leaves not_in_kitchen empty, since the recipes don\'t say what kind.'),
        ];
    }
}
