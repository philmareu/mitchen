<?php

namespace App\Ai\Agents;

use App\Enums\IngredientCategory;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Reads a recipe from photos (a handwritten card, a cookbook page, the back of a package)
 * and/or a note into its name, ingredients, substitutes, variants, equipment and steps, for
 * review.
 *
 * Runs on the default (most capable) model: handwriting takes good eyes, and a wrong amount
 * ruins a dish.
 */
#[MaxTokens(8_000)]
#[Timeout(180)]
class RecipeReader implements Agent, HasStructuredOutput
{
    use Promptable;

    public static function isConfigured(): bool
    {
        return KitchenUpdateReader::isConfigured();
    }

    public function instructions(): Stringable|string
    {
        return <<<'TEXT'
        You copy recipes into a home cook's recipe collection. The cook sends photos of a recipe (often a handwritten card, sometimes a cookbook page or the back of a package), a note, or both. The note may be the whole recipe typed out, or it may add to or correct the photos ("serves 6", "I use pinto beans instead of kidney beans"); when it does, follow the note. The cook checks everything you return before it's saved.

        You also see the ingredients in the kitchen's catalog and the equipment the kitchen has.

        Read one recipe. If the photos hold more than one, read the one the note names, else the first, and put the other recipes' names in not_understood.

        Fields:
        - name: the recipe's name as written ("Grandma's beef stew"). If none is written, a short plain name for the dish.
        - source: where it's from, when the card, page or note says ("Grandma Rose", "back of the Maseca bag", "Joy of Cooking"). Otherwise null.
        - servings: how many it serves or makes, as a whole number ("Serves 4" is 4, "Makes 2 dozen" is 24). Null when it doesn't say. Never guess.
        - ingredients: one entry per ingredient, in the recipe's order:
          - quantity: the amount in plain words and standard abbreviations: "2 cups", "1½ tsp", "1 lb", "3", "1 (15 oz) can", "a pinch", "to taste". Write out handwritten shorthand ("c." is cups, "T" or "Tbsp" is tbsp, "t" is tsp). Null when there's no amount.
          - name: the ingredient alone, in sentence case ("Yellow onion", "Masa harina", "Eggs"), without the amount or preparation. When the catalog has the same ingredient, use its catalog name exactly ("flour" is "All-purpose flour" if that's what the catalog calls it; "salt" is "Kosher salt" if that's the only salt). Never match different things: masa harina is not cornmeal, and baking soda is not baking powder.
          - note: preparation or size ("diced", "softened", "large"). Otherwise null. Alternatives go in substitutes, not here.
          - optional: true when the recipe says it's optional ("if you like", "optional", "for garnish").
          - category: the grocery aisle, used when the ingredient isn't in the catalog: produce, dairy_and_eggs, meat_and_seafood, bakery, grains_and_pasta, baking, spices_and_herbs, oils_and_condiments, canned_and_jarred, frozen, snacks, beverages or other.
        - substitutes: what the recipe says can stand in for one of its ingredients ("1 cup buttermilk, or 1 cup milk + 1 tbsp lemon juice", "honey works instead of sugar"). One entry per alternative:
          - instead_of: the name of the ingredient it replaces, exactly as you wrote it in ingredients.
          - use: the substitute's ingredients with amounts, written like ingredients (catalog names when the catalog has them). When the recipe gives no amount, null.
          - note: how to use it ("stir and let it sit 5 minutes"). Otherwise null.
          Only alternatives the recipe or the note gives; never suggest your own.
        - variants: named ways of making it that the recipe gives, chosen before cooking ("for hot salsa, add 2 habaneros", "Blueberry: fold in 1 cup blueberries", "vegetarian: skip the bacon"). Not alternatives for a missing ingredient (those are substitutes). One entry per variant:
          - name: short, as the recipe calls it ("Hot", "Blueberry", "Vegetarian").
          - notes: anything else the variant changes, such as in the method ("keep the seeds in"). Otherwise null.
          - changes: how its ingredients differ. For an ingredient already in ingredients, use exactly the same name with the variant's amount, or leave_out true when the variant goes without it. For an ingredient the recipe doesn't have, its name and amount, written like ingredients.
        - equipment: equipment the recipe needs that not every kitchen has, or that matters for the result: appliances (slow cooker, stand mixer, blender), particular cookware and bakeware (Dutch oven, cast iron skillet, 9×13 baking dish, loaf pan), and single-job tools (tortilla press, potato ricer, thermometer). Leave out everyday basics such as knives, cutting boards, spoons, measuring cups, mixing bowls and an ordinary pot or pan, unless the recipe makes a point of them. When the kitchen has the same piece, use its listed name exactly; otherwise a plain generic name ("Tortilla press").
        - steps: the method, one step per entry, in order, in the recipe's own words, tidied only to fix spelling and write out shorthand. Keep every temperature, time and amount exactly as written.
        - notes: tips, variations or remarks that aren't steps ("Freezes well", "Dad likes extra garlic"). Otherwise null.
        - not_understood: anything you couldn't read or that isn't part of the recipe, described briefly ("amount of sugar is smudged"). When handwriting is unclear, say so here rather than guessing.

        Never invent ingredients, amounts or steps the photos and note don't show. If there's no recipe at all, return an empty name with no ingredients and no steps.
        TEXT;
    }

    public function schema(JsonSchema $schema): array
    {
        $ingredient = $schema->object([
            'quantity' => $schema->string()->nullable()->required(),
            'name' => $schema->string()->required(),
            'note' => $schema->string()->nullable()->required(),
            'optional' => $schema->boolean()->required(),
            'category' => $schema->string()->enum(IngredientCategory::class)->required(),
        ])->withoutAdditionalProperties();

        $substitute = $schema->object([
            'instead_of' => $schema->string()->required(),
            'use' => $schema->array()->items($schema->object([
                'quantity' => $schema->string()->nullable()->required(),
                'name' => $schema->string()->required(),
                'category' => $schema->string()->enum(IngredientCategory::class)->required(),
            ])->withoutAdditionalProperties())->required(),
            'note' => $schema->string()->nullable()->required(),
        ])->withoutAdditionalProperties();

        $variant = $schema->object([
            'name' => $schema->string()->required(),
            'notes' => $schema->string()->nullable()->required(),
            'changes' => $schema->array()->items($schema->object([
                'quantity' => $schema->string()->nullable()->required(),
                'name' => $schema->string()->required(),
                'leave_out' => $schema->boolean()->required(),
                'category' => $schema->string()->enum(IngredientCategory::class)->required(),
            ])->withoutAdditionalProperties())->required(),
        ])->withoutAdditionalProperties();

        return [
            'name' => $schema->string()->required(),
            'source' => $schema->string()->nullable()->required(),
            'servings' => $schema->integer()->nullable()->required(),
            'ingredients' => $schema->array()->items($ingredient)->required(),
            'substitutes' => $schema->array()->items($substitute)->required(),
            'variants' => $schema->array()->items($variant)->required(),
            'equipment' => $schema->array()->items($schema->string())->required(),
            'steps' => $schema->array()->items($schema->string())->required(),
            'notes' => $schema->string()->nullable()->required(),
            'not_understood' => $schema->array()->items($schema->string())->required(),
        ];
    }
}
