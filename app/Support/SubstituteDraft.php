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
}
