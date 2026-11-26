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
}
