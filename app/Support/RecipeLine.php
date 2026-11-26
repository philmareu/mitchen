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
}
