<?php

namespace App\Support;

/**
 * A variant of a recipe being added, before it's saved: its name, notes and ingredient changes
 * (a line for one of the recipe's ingredients replaces it, or leaves it out; others are added).
 */
final readonly class VariantDraft
{
    /**
     * @param  list<RecipeLine>  $ingredients
     */
    public function __construct(
        public string $name,
        public array $ingredients = [],
        public ?string $notes = null,
    ) {}

    /**
     * @param  array<string, mixed>  $state
     */
    public static function fromArray(array $state): self
    {
        $lines = is_array($state['ingredients'] ?? null) ? array_filter($state['ingredients'], is_array(...)) : [];

        return new self(
            name: trim((string) ($state['name'] ?? '')),
            ingredients: array_values(array_map(fn (array $line): RecipeLine => RecipeLine::fromArray($line), $lines)),
            notes: filled($state['notes'] ?? null) ? trim((string) $state['notes']) : null,
        );
    }

    /**
     * @return array{name: string, notes: string|null, ingredients: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'notes' => $this->notes,
            'ingredients' => array_map(fn (RecipeLine $line): array => $line->toArray(), $this->ingredients),
        ];
    }
}
