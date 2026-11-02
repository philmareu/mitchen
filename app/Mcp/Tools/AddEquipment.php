<?php

namespace App\Mcp\Tools;

use App\Enums\EquipmentCategory;
use App\Mcp\Tools\Concerns\PresentsKitchen;
use App\Models\Equipment;
use App\Models\Recipe;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Description('Add equipment the cook has, such as a blender they never listed. Name each piece plainly in sentence case ("Blender", "Cast iron skillet"), with the make and model in brand and capacity in size; when the recipes call for it (list-equipment\'s not_in_kitchen), use their name exactly, so those recipes find it. A piece already listed by that name isn\'t added again: only the details you pass change. Returns each piece with the recipes that call for it.')]
#[IsIdempotent]
class AddEquipment extends Tool
{
    use PresentsKitchen;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): ResponseFactory
    {
        $validated = $request->validate([
            'pieces' => ['required', 'array', 'min:1', 'max:50'],
            'pieces.*.name' => ['required', 'string', 'max:255'],
            'pieces.*.category' => ['nullable', Rule::enum(EquipmentCategory::class)],
            'pieces.*.brand' => ['nullable', 'string', 'max:255'],
            'pieces.*.size' => ['nullable', 'string', 'max:255'],
            'pieces.*.count' => ['nullable', 'integer', 'min:1', 'max:999'],
            'pieces.*.notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $equipment = DB::transaction(fn (): array => array_map($this->save(...), $validated['pieces']));

        return Response::structured([
            'equipment' => array_map(fn (Equipment $piece): array => [
                ...$this->presentEquipment($piece),
                'added' => $piece->wasRecentlyCreated,
                'recipes' => Recipe::query()
                    ->whereHas('equipment', fn ($query) => $query->whereBelongsTo($piece))
                    ->orderBy('name')
                    ->pluck('name')
                    ->all(),
            ], $equipment),
        ]);
    }

    /**
     * Adds the piece, or fills in the details given for the one listed by that name. Adding one
     * links the recipes that call for it (Equipment's saved hook).
     *
     * @param  array<string, mixed>  $piece
     */
    private function save(array $piece): Equipment
    {
        $details = array_filter(
            array_map(fn (mixed $value): mixed => is_string($value) ? trim($value) : $value, Arr::only($piece, ['category', 'brand', 'size', 'count', 'notes'])),
            filled(...),
        );

        $equipment = Equipment::query()->named($piece['name'])->first();

        if ($equipment !== null) {
            $equipment->update($details);

            return $equipment;
        }

        return Equipment::create([
            'name' => Str::ucfirst(trim($piece['name'])),
            'category' => EquipmentCategory::Other,
            ...$details,
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
            'pieces' => $schema->array()
                ->items($schema->object([
                    'name' => $schema->string()->description('A plain, generic name ("Blender", "Dutch oven", "Sheet pan"), or the recipes\' name for it. No brand or count.')->required(),
                    'category' => $schema->string()->enum(EquipmentCategory::class)->description('What kind of equipment it is.'),
                    'brand' => $schema->string()->description('The make and model, such as "Vitamix 5200" or "Lodge".'),
                    'size' => $schema->string()->description('Capacity or dimensions, such as "64 oz", "10 in" or "9×13 in".'),
                    'count' => $schema->integer()->description('How many the cook has now, when more than one.'),
                    'notes' => $schema->string()->description('Where it lives or how to care for it, in the cook\'s words.'),
                ]))
                ->description('The equipment to add.')
                ->required(),
        ];
    }
}
