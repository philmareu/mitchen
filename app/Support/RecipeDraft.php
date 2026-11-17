<?php

namespace App\Support;

use App\Enums\Course;
use App\Enums\Diet;
use App\Enums\Protein;

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
     * @param  list<Diet>  $diets
     * @param  list<string>  $tags
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
        public ?string $cuisine = null,
        public ?Course $course = null,
        public ?Protein $protein = null,
        public array $diets = [],
        public array $tags = [],
    ) {}

    /**
     * What it saves on the recipe itself (the lines are saved by AddRecipe::addLines()).
     *
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return [
            'name' => $this->name,
            'source' => $this->source,
            'cuisine' => $this->cuisine,
            'course' => $this->course,
            'protein' => $this->protein,
            'diets' => array_map(fn (Diet $diet): string => $diet->value, $this->diets),
            'tags' => $this->tags,
            'servings' => $this->servings,
            'steps' => $this->steps,
            'notes' => $this->notes,
        ];
    }

    /**
     * The names of every ingredient line: its own, its substitutes' and its variants'.
     *
     * @return list<string>
     */
    public function ingredientNames(): array
    {
        return collect($this->ingredients)
            ->concat(collect($this->substitutes)->flatMap(fn (SubstituteDraft $substitute): array => $substitute->ingredients))
            ->concat(collect($this->variants)->flatMap(fn (VariantDraft $variant): array => $variant->ingredients))
            ->map(fn (RecipeLine $line): string => $line->name)
            ->values()
            ->all();
    }

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
            cuisine: filled($state['cuisine'] ?? null) ? trim((string) $state['cuisine']) : null,
            course: self::enum(Course::class, $state['course'] ?? null),
            protein: self::enum(Protein::class, $state['protein'] ?? null),
            diets: collect(is_array($state['diets'] ?? null) ? $state['diets'] : [])
                ->map(fn (mixed $diet): ?Diet => self::enum(Diet::class, $diet))
                ->filter()
                ->unique()
                ->values()
                ->all(),
            tags: collect(is_array($state['tags'] ?? null) ? $state['tags'] : [])
                ->map(fn (mixed $tag): string => is_string($tag) ? trim($tag) : '')
                ->filter()
                ->values()
                ->all(),
        );
    }

    /**
     * @template TEnum of \BackedEnum
     *
     * @param  class-string<TEnum>  $enum
     * @return TEnum|null
     */
    private static function enum(string $enum, mixed $value): ?\BackedEnum
    {
        return match (true) {
            $value instanceof $enum => $value,
            is_string($value) => $enum::tryFrom($value),
            default => null,
        };
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
            'cuisine' => $this->cuisine,
            'course' => $this->course?->value,
            'protein' => $this->protein?->value,
            'diets' => array_map(fn (Diet $diet): string => $diet->value, $this->diets),
            'tags' => $this->tags,
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
