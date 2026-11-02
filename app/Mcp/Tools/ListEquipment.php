<?php

namespace App\Mcp\Tools;

use App\Enums\EquipmentCategory;
use App\Models\Equipment;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('The kitchen\'s equipment, from appliances down to utensils: each piece\'s brand and model, size, how many there are, and the cook\'s notes (where it lives, how to care for it).')]
#[IsReadOnly]
class ListEquipment extends Tool
{
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

        return Response::structured([
            'equipment' => $equipment->map(fn (Equipment $piece): array => [
                'name' => $piece->name,
                'category' => $piece->category?->value,
                'brand' => $piece->brand,
                'size' => $piece->size,
                'count' => $piece->count,
                'notes' => $piece->notes,
            ])->all(),
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
                ->description('Only pieces whose name contains this, such as "pan".'),
            'category' => $schema->string()
                ->enum(EquipmentCategory::class)
                ->description('Only pieces of this kind.'),
        ];
    }
}
