<?php

namespace App\Filament\Resources\Recipes\Pages;

use App\Enums\Readiness;
use App\Filament\Actions\QuickAddRecipeAction;
use App\Filament\Resources\Recipes\RecipeResource;
use App\Models\Recipe;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListRecipes extends ListRecords
{
    protected static string $resource = RecipeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            QuickAddRecipeAction::make(),
            CreateAction::make()
                ->label('Add recipe'),
        ];
    }

    /**
     * What to make, in tabs by how close the kitchen is to each recipe, made the closest way.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $readiness = Recipe::query()->readinessByKey();

        return [
            'all' => Tab::make('All'),
            ...collect(Readiness::cases())
                ->mapWithKeys(function (Readiness $tier) use ($readiness): array {
                    $keys = $readiness->filter(fn (Readiness $closest): bool => $closest === $tier)->keys()->all();

                    return [$tier->value => Tab::make($tier->getLabel())
                        ->badge(count($keys) ?: null)
                        ->badgeColor($tier->getColor())
                        ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereKey($keys))];
                })
                ->all(),
        ];
    }
}
