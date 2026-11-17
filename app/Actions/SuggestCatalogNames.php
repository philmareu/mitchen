<?php

namespace App\Actions;

use App\Ai\Agents\KitchenUpdateReader;
use App\Ai\Agents\NameMatcher;
use App\Models\Equipment;
use App\Models\Ingredient;
use App\Support\Names;
use Illuminate\Support\Collection;
use Throwable;

/**
 * For names that aren't in the catalog yet, the catalog ingredient each may mean ("Scallions"
 * may be "Green onions"), or for equipment the kitchen hasn't got, the piece it may mean
 * ("Blender" may be the "Vitamix"), asked of the fast model so the review can offer it. Never
 * links by itself: Salt and Kosher salt can be a real choice. A suggestion has to be a listed
 * name, and when the assistant can't be reached there are simply none.
 */
class SuggestCatalogNames
{
    /**
     * @param  iterable<string>  $names
     * @return array<string, string> The catalog name, keyed by the new name in lowercase.
     */
    public function handle(iterable $names): array
    {
        return $this->suggest($names, Ingredient::query()->orderBy('name')->pluck('name'), 'The catalog');
    }

    /**
     * @param  iterable<string>  $names
     * @return array<string, string> The equipment's name, keyed by the new name in lowercase.
     */
    public function equipment(iterable $names): array
    {
        return $this->suggest($names, Equipment::query()->orderBy('name')->pluck('name'), 'The kitchen\'s equipment');
    }

    /**
     * @param  iterable<string>  $names
     * @param  Collection<int, string>  $catalog
     * @return array<string, string>
     */
    private function suggest(iterable $names, Collection $catalog, string $heading): array
    {
        $new = collect($names)
            ->map(fn (string $name): string => trim($name))
            ->filter(fn (string $name): bool => $name !== '' && Names::search($name, $catalog) === null)
            ->unique(fn (string $name): string => mb_strtolower($name))
            ->values();

        if ($new->isEmpty() || $catalog->isEmpty() || ! KitchenUpdateReader::isConfigured()) {
            return [];
        }

        try {
            $response = NameMatcher::make()->prompt($this->prompt($new, $catalog, $heading));
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }

        $matches = $response['matches'] ?? null;

        return collect(is_array($matches) ? $matches : [])
            ->filter(fn (mixed $match): bool => is_array($match) && is_string($match['name'] ?? null) && is_string($match['catalog_name'] ?? null))
            ->mapWithKeys(function (array $match) use ($new, $catalog): array {
                $asked = Names::search($match['name'], $new);
                $listed = $catalog->first(fn (string $name): bool => mb_strtolower($name) === mb_strtolower(trim($match['catalog_name'])));

                return $asked === null || $listed === null ? [] : [mb_strtolower($new[$asked]) => $listed];
            })
            ->all();
    }

    /**
     * @param  Collection<int, covariant string>  $new
     * @param  Collection<int, string>  $catalog
     */
    private function prompt(Collection $new, Collection $catalog, string $heading): string
    {
        return "New names:\n- ".$new->join("\n- ")."\n\n{$heading}: ".$catalog->join(', ');
    }
}
