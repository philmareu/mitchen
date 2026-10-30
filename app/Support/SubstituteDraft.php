<?php

namespace App\Support;

/**
 * A substitute for one of a recipe's ingredients, before it's saved: instead of buttermilk,
 * 1 cup milk + 1 tbsp lemon juice.
 */
final readonly class SubstituteDraft
{
    /**
     * @param  string  $insteadOf  The name of the recipe's ingredient it stands in for.
     * @param  list<RecipeLine>  $ingredients
     */
    public function __construct(
        public string $insteadOf,
        public array $ingredients,
        public ?string $note = null,
    ) {}

    /**
     * @param  array<string, mixed>  $state
     */
    public static function fromArray(array $state): self
    {
        $lines = is_array($state['ingredients'] ?? null) ? array_filter($state['ingredients'], is_array(...)) : [];

        return new self(
            insteadOf: trim((string) ($state['instead_of'] ?? '')),
            ingredients: array_values(array_map(fn (array $line): RecipeLine => RecipeLine::fromArray($line), $lines)),
            note: filled($state['note'] ?? null) ? trim((string) $state['note']) : null,
        );
    }

    /**
     * @return array{instead_of: string, note: string|null, ingredients: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'instead_of' => $this->insteadOf,
            'note' => $this->note,
            'ingredients' => array_map(fn (RecipeLine $line): array => $line->toArray(), $this->ingredients),
        ];
    }
}
