<?php

namespace App\Models\Builders;

use App\Models\RecipeEquipment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * @extends Builder<RecipeEquipment>
 */
class RecipeEquipmentBuilder extends Builder
{
    /**
     * Equipment the recipes call for that the kitchen doesn't have, by name (ignoring case, spelled
     * as it was first written), with the names of the recipes that call for it.
     *
     * @return Collection<string, list<string>>
     */
    public function missingFromKitchen(): Collection
    {
        return $this->whereNull('equipment_id')
            ->with('recipe:id,name')
            ->oldest('id')
            ->get()
            ->toBase()
            ->groupBy(fn (RecipeEquipment $piece): string => mb_strtolower($piece->name))
            ->mapWithKeys(fn (Collection $pieces): array => [
                $pieces->first()->name => array_values($pieces
                    ->map(fn (RecipeEquipment $piece): string => $piece->recipe->name)
                    ->unique()
                    ->sort(SORT_NATURAL | SORT_FLAG_CASE)
                    ->all()),
            ])
            ->sortKeys(SORT_NATURAL | SORT_FLAG_CASE);
    }
}
