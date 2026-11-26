<?php

namespace App\Actions;

use App\Enums\Course;
use App\Enums\Diet;
use App\Enums\IngredientCategory;
use App\Enums\Protein;
use App\Models\Equipment;
use App\Models\Ingredient;
use App\Support\Names;
use App\Support\RecipeDraft;
use App\Support\RecipeLine;
use App\Support\SubstituteDraft;
use App\Support\VariantDraft;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Turns a recipe an assistant sent (over the MCP server) into a draft to save, checked against
 * the kitchen: names that match the catalog or the kitchen's equipment (ignoring case, singular
 * or plural) take their spelling, servings that aren't a positive number are dropped, empty
 * lines are skipped, and substitutes for an ingredient the recipe doesn't have are dropped.
 */
class PrepareRecipeDraft
{
    /**
     * @param  array<string, mixed>  $recipe  As add-recipe and update-recipe take it (TakesRecipes).
     */
    public function handle(array $recipe): RecipeDraft
    {
        $ingredients = Ingredient::query()->orderBy('name')->pluck('name');
        $equipment = Equipment::query()->orderBy('name')->pluck('name');

        $lines = collect($this->list($recipe['ingredients'] ?? null))
            ->map(fn (mixed $line): ?RecipeLine => is_array($line) ? $this->line($line, $ingredients) : null)
            ->filter()
            ->values()
            ->all();

        $steps = $this->texts($recipe['steps'] ?? null);
        $servings = $recipe['servings'] ?? null;

        return new RecipeDraft(
            name: $this->text($recipe['name'] ?? null) ?? ($lines === [] && $steps === [] ? '' : 'Untitled recipe'),
            source: $this->text($recipe['source'] ?? null),
            servings: is_numeric($servings) && $servings >= 1 ? min((int) $servings, 999) : null,
            ingredients: $lines,
            substitutes: collect($this->list($recipe['substitutes'] ?? null))
                ->map(fn (mixed $substitute): ?SubstituteDraft => is_array($substitute) ? $this->substitute($substitute, $lines, $ingredients) : null)
                ->filter()
                ->values()
                ->all(),
            variants: collect($this->list($recipe['variants'] ?? null))
                ->map(fn (mixed $variant): ?VariantDraft => is_array($variant) ? $this->variant($variant, $lines, $ingredients) : null)
                ->filter()
                ->values()
                ->all(),
            equipment: collect($this->texts($recipe['equipment'] ?? null))
                ->map(fn (string $name): string => $this->spelledAs($name, $equipment))
                ->unique(fn (string $name): string => mb_strtolower($name))
                ->values()
                ->all(),
            steps: $steps,
            notes: $this->text($recipe['notes'] ?? null),
            cuisine: ($cuisine = $this->text($recipe['cuisine'] ?? null)) === null ? null : Str::limit($cuisine, 100, ''),
            course: Course::tryFrom((string) $this->text($recipe['course'] ?? null)),
            protein: Protein::tryFrom((string) $this->text($recipe['protein'] ?? null)),
            diets: collect($this->texts($recipe['diets'] ?? null))
                ->map(fn (string $diet): ?Diet => Diet::tryFrom($diet))
                ->filter()
                ->unique()
                ->values()
                ->all(),
            tags: collect($this->texts($recipe['tags'] ?? null))
                ->map(fn (string $tag): string => Str::limit($tag, 50, ''))
                ->unique(fn (string $tag): string => mb_strtolower($tag))
                ->take(10)
                ->values()
                ->all(),
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
     * The listed name it matches, ignoring case, singular or plural (Names), else the name in sentence case.
     *
     * @param  Collection<int, string>  $names
     */
    private function spelledAs(string $name, Collection $names): string
    {
        $key = Names::search($name, $names);

        return $key === null ? Str::ucfirst($name) : $names[$key];
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
     * What an assistant sends is untrusted: anything that isn't a list counts as empty.
     *
     * @return list<mixed>
     */
    private function list(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }
}
