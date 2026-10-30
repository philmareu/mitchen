<?php

namespace App\Support;

/**
 * A recipe read from photos or a note, waiting for the cook to check it.
 */
final readonly class RecipeDraft
{
    /**
     * @param  list<RecipeLine>  $ingredients
     * @param  list<SubstituteDraft>  $substitutes
     * @param  list<VariantDraft>  $variants
     * @param  list<string>  $equipment
     * @param  list<string>  $steps
     * @param  list<string>  $notUnderstood
     * @param  string|null  $changeNote  For a revision: what the assistant changed and why.
     */
    public function __construct(
        public string $name,
        public ?string $source = null,
        public ?int $servings = null,
        public array $ingredients = [],
        public array $substitutes = [],
        public array $variants = [],
        public array $equipment = [],
        public array $steps = [],
        public ?string $notes = null,
        public array $notUnderstood = [],
        public ?string $changeNote = null,
    ) {}

    /**
     * Nothing that looks like a recipe was found.
     */
    public function isEmpty(): bool
    {
        return $this->ingredients === [] && $this->steps === [];
    }

    /**
     * Rebuild a draft from the review form's state.
     *
     * @param  array<string, mixed>  $state
     */
    public static function fromArray(array $state): self
    {
        $servings = $state['servings'] ?? null;

        return new self(
            name: trim((string) $state['name']),
            source: filled($state['source'] ?? null) ? trim((string) $state['source']) : null,
            servings: is_numeric($servings) ? (int) $servings : null,
            ingredients: collect(self::rows($state['ingredients'] ?? null))
                ->map(fn (array $line): RecipeLine => RecipeLine::fromArray($line))
                ->values()
                ->all(),
            substitutes: collect(self::rows($state['substitutes'] ?? null))
                ->map(fn (array $substitute): SubstituteDraft => SubstituteDraft::fromArray($substitute))
                ->filter(fn (SubstituteDraft $substitute): bool => $substitute->insteadOf !== '' && $substitute->ingredients !== [])
                ->values()
                ->all(),
            variants: collect(self::rows($state['variants'] ?? null))
                ->map(fn (array $variant): VariantDraft => VariantDraft::fromArray($variant))
                ->filter(fn (VariantDraft $variant): bool => $variant->name !== '')
                ->values()
                ->all(),
            equipment: collect(self::rows($state['equipment'] ?? null))
                ->map(fn (array $piece): string => trim((string) ($piece['name'] ?? '')))
                ->filter()
                ->values()
                ->all(),
            steps: collect(is_array($state['steps'] ?? null) ? $state['steps'] : [])
                ->map(fn (mixed $step): string => trim((string) $step))
                ->filter()
                ->values()
                ->all(),
            notes: filled($state['notes'] ?? null) ? trim((string) $state['notes']) : null,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rows(mixed $state): array
    {
        return is_array($state) ? array_values(array_filter($state, is_array(...))) : [];
    }

    /**
     * The review form's state.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'source' => $this->source,
            'servings' => $this->servings,
            'ingredients' => array_map(fn (RecipeLine $line): array => $line->toArray(), $this->ingredients),
            'substitutes' => array_map(fn (SubstituteDraft $substitute): array => $substitute->toArray(), $this->substitutes),
            'variants' => array_map(fn (VariantDraft $variant): array => $variant->toArray(), $this->variants),
            'equipment' => array_map(fn (string $name): array => ['name' => $name], $this->equipment),
            'steps' => $this->steps,
            'notes' => $this->notes,
            'not_understood' => $this->notUnderstood,
            'version_note' => $this->changeNote,
        ];
    }
}
