<?php

namespace App\Actions;

use App\Ai\Agents\RecipeReader;
use App\Enums\IngredientCategory;
use App\Models\Equipment;
use App\Models\Ingredient;
use App\Support\RecipeDraft;
use App\Support\RecipeLine;
use App\Support\SubstituteDraft;
use App\Support\VariantDraft;
use ArrayAccess;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Files\LocalImage;

/**
 * Asks the assistant for the recipe in the photos or note, then tidies its answer: names
 * that match the catalog or the kitchen's equipment (ignoring case) take their spelling,
 * servings that aren't a positive number are dropped, empty lines are skipped, and substitutes
 * for an ingredient the recipe doesn't have are dropped.
 */
class ReadRecipe
{
    /**
     * @param  list<string>  $photos  Paths to photos of the recipe.
     */
    public function handle(string $note, array $photos = []): RecipeDraft
    {
        $ingredients = Ingredient::query()->orderBy('name')->pluck('name');
        $equipment = Equipment::query()->orderBy('name')->pluck('name');

        $response = RecipeReader::make()->prompt(
            $this->prompt($note, count($photos), $ingredients, $equipment),
            attachments: array_map(fn (string $path): LocalImage => Image::fromPath($path), $photos),
        );

        return $this->tidy($response, $ingredients, $equipment);
    }

    /**
     * Turn the assistant's answer into a draft. Also used for revisions (ReviseRecipe).
     *
     * @param  array<string, mixed>|ArrayAccess<string, mixed>  $response
     * @param  Collection<int, string>  $ingredients  The catalog's names.
     * @param  Collection<int, string>  $equipment  The kitchen's equipment names.
     */
    public function tidy(array|ArrayAccess $response, Collection $ingredients, Collection $equipment): RecipeDraft
    {
        $lines = collect($this->list($response['ingredients'] ?? null))
            ->map(fn (mixed $line): ?RecipeLine => is_array($line) ? $this->line($line, $ingredients) : null)
            ->filter()
            ->values()
            ->all();

        $steps = $this->texts($response['steps'] ?? null);
        $servings = $response['servings'] ?? null;

        return new RecipeDraft(
            name: $this->text($response['name'] ?? null) ?? ($lines === [] && $steps === [] ? '' : 'Untitled recipe'),
            source: $this->text($response['source'] ?? null),
            servings: is_numeric($servings) && $servings >= 1 ? min((int) $servings, 999) : null,
            ingredients: $lines,
            substitutes: collect($this->list($response['substitutes'] ?? null))
                ->map(fn (mixed $substitute): ?SubstituteDraft => is_array($substitute) ? $this->substitute($substitute, $lines, $ingredients) : null)
                ->filter()
                ->values()
                ->all(),
            variants: collect($this->list($response['variants'] ?? null))
                ->map(fn (mixed $variant): ?VariantDraft => is_array($variant) ? $this->variant($variant, $lines, $ingredients) : null)
                ->filter()
                ->values()
                ->all(),
            equipment: collect($this->texts($response['equipment'] ?? null))
                ->map(fn (string $name): string => $this->spelledAs($name, $equipment))
                ->unique(fn (string $name): string => mb_strtolower($name))
                ->values()
                ->all(),
            steps: $steps,
            notes: $this->text($response['notes'] ?? null),
            notUnderstood: $this->texts($response['not_understood'] ?? null),
            changeNote: $this->text($response['summary'] ?? null),
        );
    }

    /**
     * @param  array<string, mixed>  $line
     * @param  Collection<int, string>  $catalog
     */
    private function line(array $line, Collection $catalog): ?RecipeLine
    {
        $name = $this->text($line['name'] ?? null);

        if ($name === null) {
            return null;
        }

        return new RecipeLine(
            name: $this->spelledAs($name, $catalog),
            quantity: $this->text($line['quantity'] ?? null),
            note: $this->text($line['note'] ?? null),
            isOptional: ($line['optional'] ?? false) === true,
            isLeftOut: ($line['leave_out'] ?? false) === true,
            category: IngredientCategory::tryFrom((string) ($line['category'] ?? '')) ?? IngredientCategory::Other,
        );
    }

    /**
     * A substitute for one of the recipe's lines, taking that line's spelling; null when it
     * isn't for one of them or has nothing in it.
     *
     * @param  array<string, mixed>  $substitute
     * @param  list<RecipeLine>  $lines
     * @param  Collection<int, string>  $catalog
     */
    private function substitute(array $substitute, array $lines, Collection $catalog): ?SubstituteDraft
    {
        $insteadOf = $this->spelledAs((string) $this->text($substitute['instead_of'] ?? null), collect($lines)->map(fn (RecipeLine $line): string => $line->name));

        $use = collect($this->list($substitute['use'] ?? null))
            ->map(fn (mixed $line): ?RecipeLine => is_array($line) ? $this->line($line, $catalog) : null)
            ->filter()
            ->values()
            ->all();

        if ($use === [] || ! collect($lines)->contains(fn (RecipeLine $line): bool => $line->name === $insteadOf)) {
            return null;
        }

        return new SubstituteDraft($insteadOf, $use, $this->text($substitute['note'] ?? null));
    }

    /**
     * A variant, its changes to the recipe's lines taking their spelling; null without a name.
     *
     * @param  array<string, mixed>  $variant
     * @param  list<RecipeLine>  $lines
     * @param  Collection<int, string>  $catalog
     */
    private function variant(array $variant, array $lines, Collection $catalog): ?VariantDraft
    {
        $name = $this->text($variant['name'] ?? null);

        if ($name === null) {
            return null;
        }

        $changes = collect($this->list($variant['changes'] ?? null))
            ->map(fn (mixed $line): ?RecipeLine => is_array($line) ? $this->line($line, collect($lines)->map(fn (RecipeLine $own): string => $own->name)->concat($catalog)) : null)
            ->filter()
            ->values()
            ->all();

        return new VariantDraft(Str::ucfirst($name), $changes, $this->text($variant['notes'] ?? null));
    }

    /**
     * The listed name it matches, ignoring case, else the name in sentence case.
     *
     * @param  Collection<int, string>  $names
     */
    private function spelledAs(string $name, Collection $names): string
    {
        return $names->first(fn (string $listed): bool => mb_strtolower($listed) === mb_strtolower($name))
            ?? Str::ucfirst($name);
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @return list<string>
     */
    private function texts(mixed $values): array
    {
        return collect($this->list($values))
            ->map(fn (mixed $value): ?string => $this->text($value))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * The model's output is untrusted: anything that isn't a list counts as empty.
     *
     * @return list<mixed>
     */
    private function list(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    /**
     * @param  Collection<int, string>  $ingredients
     * @param  Collection<int, string>  $equipment
     */
    private function prompt(string $note, int $photoCount, Collection $ingredients, Collection $equipment): string
    {
        $note = trim($note) === '' ? '(no note, just the photos)' : $note;
        $photos = $photoCount === 0 ? 'none' : (string) $photoCount;
        $catalog = $ingredients->join(', ') ?: '(empty)';
        $kitchen = $equipment->join(', ') ?: '(none listed)';

        return <<<TEXT
        The cook's note:
        """
        {$note}
        """

        Photos attached: {$photos}

        Ingredients in the catalog: {$catalog}

        Equipment the kitchen has: {$kitchen}
        TEXT;
    }
}
