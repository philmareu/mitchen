<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\PresentsKitchen;
use App\Models\InventoryItem;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('What to use soon: items with a use-by date in the next week (or as many days as you ask), soonest first, including any already past it. days_left is negative for those.')]
#[IsReadOnly]
class UseSoon extends Tool
{
    use PresentsKitchen;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): ResponseFactory
    {
        $validated = $request->validate([
            'days' => ['nullable', 'integer', 'min:0', 'max:60'],
        ]);

        $items = InventoryItem::query()
            ->with('ingredient')
            ->useSoon($validated['days'] ?? 7)
            ->orderBy('expires_on')
            ->get();

        return Response::structured([
            'items' => $items->map(fn (InventoryItem $item): array => [
                'ingredient' => $item->ingredient->name,
                ...$this->presentItem($item),
                'days_left' => (int) today()->diffInDays($item->expires_on, absolute: false),
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
            'days' => $schema->integer()
                ->min(0)
                ->max(60)
                ->default(7)
                ->description('How many days ahead to look.'),
        ];
    }
}
