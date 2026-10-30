<?php

namespace App\Support;

use App\Enums\IngredientCategory;

/**
 * One ingredient line of a recipe being added, before it's saved.
 */
final readonly class RecipeLine
{
    /**
     * @param  IngredientCategory|null  $category  The aisle, for an ingredient new to the catalog.
     * @param  bool  $isLeftOut  A variant's change that goes without the ingredient.
     */
    public function __construct(
        public string $name,
        public ?string $quantity = null,
        public ?string $note = null,
        public bool $isOptional = false,
        public ?IngredientCategory $category = null,
        public bool $isLeftOut = false,
    ) {}

    /**
     * @param  array<string, mixed>  $state
     */
    public static function fromArray(array $state): self
    {
        $category = $state['category'] ?? null;

        return new self(
            name: trim((string) $state['name']),
            quantity: filled($state['quantity'] ?? null) ? trim((string) $state['quantity']) : null,
            note: filled($state['note'] ?? null) ? trim((string) $state['note']) : null,
            isOptional: (bool) ($state['is_optional'] ?? false),
            category: $category instanceof IngredientCategory ? $category : IngredientCategory::tryFrom((string) $category),
            isLeftOut: (bool) ($state['is_left_out'] ?? false),
        );
    }

    /**
     * @return array{name: string, quantity: string|null, note: string|null, is_optional: bool, is_left_out: bool, category: string|null}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'quantity' => $this->quantity,
            'note' => $this->note,
            'is_optional' => $this->isOptional,
            'is_left_out' => $this->isLeftOut,
            'category' => $this->category?->value,
        ];
    }
}
