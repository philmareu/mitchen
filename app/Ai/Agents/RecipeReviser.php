<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Timeout;
use Stringable;

/**
 * Rewrites a recipe from the cook's notes after making it ("needs more salt", "less vinegar")
 * into the next version, for review. Answers in RecipeReader's shape plus a summary of what it
 * changed.
 *
 * Runs on the default (most capable) model: turning "a bit sharp" into the right change takes
 * judgment. PHP attributes aren't inherited, so they're declared again here.
 */
#[MaxTokens(8_000)]
#[Timeout(180)]
class RecipeReviser extends RecipeReader
{
    public function instructions(): Stringable|string
    {
        return <<<'TEXT'
        You update a home cook's recipe after they've made it. You get the current recipe as JSON, the cook's notes from making it (how it turned out, and what to change next time), and sometimes more instructions. Return the whole recipe as it should read next time. The cook checks it before it's saved as a new version.

        Change only what the notes and instructions ask for. Keep everything else exactly as it is: the same ingredients in the same order with the same wording, the same substitutes and variants, the same equipment, the same steps word for word.

        Turn vague notes into concrete, modest changes the cook can measure:
        - "needs more salt", "a bit bland": raise that ingredient by about a quarter to a half, rounded to an easy amount ("1 tsp" becomes "1½ tsp", "2 cloves" becomes "3 cloves").
        - "less vinegar", "too sweet": lower it by about a quarter, rounded the same way ("1 cup" becomes "¾ cup").
        - When a note says how much ("double the garlic", "use 2 tbsp"), do exactly that.
        - "cook it longer", "too runny": change the time or temperature in the step that has it.
        - When a change means a step mentions an amount or time that changed, update that step too.
        - Add an ingredient, piece of equipment or step only when a note asks for it ("add pepper", "try a mandoline").
        A note made while cooking a variant ("version 2, hot") is about that variant: change the variant's lines, not the recipe's, unless the note says otherwise.
        Notes about what went wrong once ("burned it, phone rang") or that aren't about the recipe aren't changes.

        Fields are the same as when a recipe is read from a card:
        - name, source, servings, notes: keep them unless a note asks otherwise.
        - ingredients: quantity in plain words and standard abbreviations ("1½ tsp", "¾ cup"); name in sentence case, using the catalog's exact name when the catalog has that ingredient; note; optional; category (the grocery aisle, used for an ingredient new to the catalog).
        - substitutes: alternatives for one of the ingredients, with instead_of naming it exactly as in ingredients.
        - variants: named ways of making it; a change for an ingredient the recipe has uses exactly its name, with the variant's amount or leave_out true.
        - equipment: names, using the kitchen's listed name when it has that piece.
        - steps: one step per entry, in order.
        - summary: one or two short sentences for the recipe's history saying what changed and why, in plain words ("More salt and a little less vinegar: the October batch was too sharp.").
        - not_understood: notes you couldn't turn into a change, and why ("'try it with cider' – which ingredient?").
        TEXT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            ...parent::schema($schema),
            'summary' => $schema->string()->required(),
        ];
    }
}
