<?php

namespace App\Actions;

use App\Ai\Agents\RecipeReader;
use App\Enums\IngredientCategory;
use App\Models\Equipment;
use App\Models\Ingredient;
use App\Support\RecipeDraft;
use App\Support\RecipeLine;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Files\LocalImage;

/**
 * Asks the assistant for the recipe in the photos or note, then tidies its answer: names
 * that match the catalog or the kitchen's equipment (ignoring case) take their spelling,
 * servings that aren't a positive number are dropped, and empty lines are skipped.
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
            equipment: collect($this->texts($response['equipment'] ?? null))
                ->map(fn (string $name): string => $this->spelledAs($name, $equipment))
                ->unique(fn (string $name): string => mb_strtolower($name))
                ->values()
                ->all(),
            steps: $steps,
            notes: $this->text($response['notes'] ?? null),
            notUnderstood: $this->texts($response['not_understood'] ?? null),
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
            category: IngredientCategory::tryFrom((string) ($line['category'] ?? '')) ?? IngredientCategory::Other,
        );
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
