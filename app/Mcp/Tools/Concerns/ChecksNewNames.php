<?php

namespace App\Mcp\Tools\Concerns;

use App\Models\Equipment;
use App\Models\Ingredient;
use App\Models\RecipeEquipment;
use App\Support\Names;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Collection;

/**
 * Stops a tool before it saves a name close to one the kitchen already has ("White onion" with
 * "Yellow onion" in the catalog, "cilantor" for "Cilantro", "Skillet" with "Cast iron skillet"
 * listed), so the assistant checks with the cook instead of the kitchen quietly gaining a
 * one-off that's always out, a recipe that needs equipment the kitchen has, or a second piece.
 * Names the cook confirmed go in the tool's confirmation parameter, and the tool is called again.
 */
trait ChecksNewNames
{
    /**
     * Why nothing can be saved yet, or null when no check found anything.
     */
    protected function stopForNames(?string ...$checks): ?string
    {
        $checks = array_filter($checks);

        return $checks === [] ? null : 'Nothing was saved. '.implode(' ', $checks).' Then call again.';
    }

    /**
     * @return array<string, mixed>
     */
    protected function newIngredientRules(): array
    {
        return $this->confirmedNameRules('new_ingredients');
    }

    protected function newIngredientsSchema(JsonSchema $schema): Type
    {
        return $schema->array()
            ->items($schema->string())
            ->description('Names that aren\'t in the catalog and that the cook confirmed are new, not another name for something it has. Only needed after this tool asks.');
    }

    /**
     * Names new to the catalog that are close to ones in it and that the cook hasn't confirmed.
     *
     * @param  iterable<string>  $names
     * @param  list<string>  $confirmed
     */
    protected function newIngredientsToCheck(iterable $names, array $confirmed): ?string
    {
        $catalog = Ingredient::query()->orderBy('name')->pluck('name');
        $toCheck = $this->closeNames($names, $confirmed, $catalog);

        if ($toCheck->isEmpty()) {
            return null;
        }

        $list = $toCheck->map(fn (array $close, string $name): string => "{$name} (the catalog has ".implode(', ', $close).')')->join('; ');

        return "These aren't in the catalog, but it has ingredients with close names: {$list}. Ask the cook whether each is one they already have. Use the catalog name if it is; if it's really something new, list it in new_ingredients.";
    }

    /**
     * @return array<string, mixed>
     */
    protected function equipmentNotInKitchenRules(): array
    {
        return $this->confirmedNameRules('equipment_not_in_kitchen');
    }

    protected function equipmentNotInKitchenSchema(JsonSchema $schema): Type
    {
        return $schema->array()
            ->items($schema->string())
            ->description('Equipment the recipe needs that the cook confirmed they don\'t have under another name. Only needed after this tool asks.');
    }

    /**
     * Equipment a recipe calls for that the kitchen hasn't got by that name but has pieces with
     * close names, and that the cook hasn't confirmed they don't have.
     *
     * @param  iterable<string>  $names
     * @param  list<string>  $confirmed
     */
    protected function equipmentNotInKitchenToCheck(iterable $names, array $confirmed): ?string
    {
        $listed = Equipment::query()->orderBy('name')->pluck('name');
        $toCheck = $this->closeNames($names, $confirmed, $listed);

        if ($toCheck->isEmpty()) {
            return null;
        }

        $list = $toCheck->map(fn (array $close, string $name): string => "{$name} (the kitchen has ".implode(', ', $close).')')->join('; ');

        return "The kitchen has no equipment by these names, but has pieces with close names: {$list}. Ask the cook whether each is a piece they have. Use the kitchen's name if it is; if they don't have it, list it in equipment_not_in_kitchen.";
    }

    /**
     * @return array<string, mixed>
     */
    protected function newEquipmentRules(): array
    {
        return $this->confirmedNameRules('new_equipment');
    }

    protected function newEquipmentSchema(JsonSchema $schema): Type
    {
        return $schema->array()
            ->items($schema->string())
            ->description('Names that aren\'t listed and that the cook confirmed are another piece, not one already listed or the one the recipes call for. Only needed after this tool asks.');
    }

    /**
     * Pieces about to be added under names close to a listed piece or to equipment the recipes
     * call for (naming it the recipes' way is what links them), that the cook hasn't confirmed.
     *
     * @param  iterable<string>  $names
     * @param  list<string>  $confirmed
     */
    protected function newEquipmentToCheck(iterable $names, array $confirmed): ?string
    {
        $listed = Equipment::query()->orderBy('name')->pluck('name');
        $wanted = RecipeEquipment::query()->missingFromKitchen()->keys()->values();
        $toCheck = $this->closeNames($names, $confirmed, $listed->concat($wanted));

        if ($toCheck->isEmpty()) {
            return null;
        }

        $list = $toCheck->map(function (array $close, string $name) use ($listed): string {
            [$has, $calledFor] = collect($close)->partition(fn (string $close): bool => $listed->contains($close));

            return $name.' ('.collect([
                $has->isEmpty() ? null : 'the kitchen has '.$has->join(', '),
                $calledFor->isEmpty() ? null : 'recipes call for '.$calledFor->join(', '),
            ])->filter()->join('; ').')';
        })->join('; ');

        return "These aren't listed, but close names are: {$list}. Ask the cook whether each is a piece already listed or the one the recipes call for. Use that name if it is; if it's really another piece, list it in new_equipment.";
    }

    /**
     * @return array<string, mixed>
     */
    private function confirmedNameRules(string $parameter): array
    {
        return [
            $parameter => ['nullable', 'array', 'max:100'],
            "{$parameter}.*" => ['string', 'max:255'],
        ];
    }

    /**
     * The names that aren't known or confirmed, each with the known names close to it. Names
     * with nothing close aren't asked about.
     *
     * @param  iterable<string>  $names
     * @param  list<string>  $confirmed
     * @param  Collection<int, string>  $known
     * @return Collection<string, non-empty-list<string>>
     */
    private function closeNames(iterable $names, array $confirmed, Collection $known): Collection
    {
        $confirmed = collect($confirmed);

        return collect($names)
            ->map(fn (string $name): string => trim($name))
            ->unique(fn (string $name): string => mb_strtolower($name))
            ->reject(fn (string $name): bool => $name === '' || Names::search($name, $known) !== null || Names::search($name, $confirmed) !== null)
            ->mapWithKeys(fn (string $name): array => [$name => Names::close($name, $known)])
            ->filter();
    }
}
