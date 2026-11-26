<?php

namespace App\Support;

use App\Enums\Course;
use App\Enums\Diet;
use App\Enums\Protein;

/**
 * A recipe to save, as the cook agreed it: checked against the kitchen by PrepareRecipeDraft
 * when an assistant sends it.
 */
final readonly class RecipeDraft
{
    /**
     * @param  list<RecipeLine>  $ingredients
     * @param  list<SubstituteDraft>  $substitutes
     * @param  list<VariantDraft>  $variants
     * @param  list<string>  $equipment
     * @param  list<string>  $steps
     * @param  list<Diet>  $diets
     * @param  list<string>  $tags
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
}
