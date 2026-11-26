<?php

namespace App\Filament\Widgets;

use App\Actions\AddToShoppingList;
use App\Enums\Readiness;
use App\Filament\Resources\Recipes\RecipeResource;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\On;

/**
 * Suggestions: recipes ready to cook, a quick shop away, or worth planning ahead for, closest
 * first, and among those the ones made longest ago first. Recipes that need a bigger shop or
 * equipment the kitchen hasn't got are left to the Recipes page.
 */
class WhatToCook extends TableWidget
{
    protected static ?int $sort = 0;

    protected static ?string $heading = 'What to cook';

    protected int|string|array $columnSpan = 'full';

    /**
     * @var list<Readiness>
     */
    private const array SUGGESTED = [Readiness::Ready, Readiness::QuickShop, Readiness::PlanAhead];

    #[On('inventory-updated')]
    public function refreshAfterQuickUpdate(): void
    {
        // Re-rendering re-runs the table query.
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => self::suggestions())
            ->recordUrl(fn (Recipe $record): string => RecipeResource::getUrl('view', ['record' => $record]))
            ->paginated([10, 25, 50])
            ->emptyStateIcon(Heroicon::OutlinedBookOpen)
            ->emptyStateHeading('Nothing ready or close')
            ->emptyStateDescription('Recipes you can make now, after a quick shop, or with a little planning show up here.')
            ->columns([
                TextColumn::make('name')
                    ->label('Recipe')
                    ->weight('medium')
                    ->description(fn (Recipe $record): ?string => $record->source),
                TextColumn::make('ready')
                    ->label('What it takes')
                    ->state(fn (Recipe $record): Readiness => $record->closestShortfall()->readiness())
                    ->badge()
                    ->description(fn (Recipe $record): ?string => $record->closestShortfall()->summary())
                    ->wrap(),
                TextColumn::make('cook_logs_max_cooked_on')
                    ->label('Last made')
                    ->date('M j, Y')
                    ->placeholder('Not yet'),
            ])
            ->recordActions([
                Action::make('addToShoppingList')
                    ->label('Add to shopping list')
                    ->icon(Heroicon::OutlinedShoppingCart)
                    ->color('gray')
                    ->tooltip(fn (Recipe $record): string => $record->closestShortfall()->toBuy()
                        ->map(fn (RecipeIngredient $line): string => $line->name)
                        ->join(', '))
                    ->visible(fn (Recipe $record): bool => $record->closestShortfall()->toBuy()->isNotEmpty())
                    ->action(function (Recipe $record, AddToShoppingList $add): void {
                        $shortfall = $record->closestShortfall();
                        $added = $add->fromRecipe($record, $shortfall->toBuy()->modelKeys(), variant: $shortfall->variant);

                        Notification::make()
                            ->title(e('Added '.collect($added)->join(', ', ' and ').' to the shopping list'))
                            ->success()
                            ->send();
                    }),
            ]);
    }

    /**
     * Ordered closest first (ready, then a quick shop, then plan ahead), then by when it was
     * last made, never made first.
     *
     * @return Builder<Recipe>
     */
    private static function suggestions(): Builder
    {
        $readiness = Recipe::query()
            ->readinessByKey()
            ->filter(fn (Readiness $closest): bool => in_array($closest, self::SUGGESTED, true));

        $query = Recipe::query()
            ->with([
                ...Recipe::KITCHEN,
                'ingredients.ingredient.shoppingListItems',
                'variants.ingredients.ingredient.shoppingListItems',
            ])
            ->withMax('cookLogs', 'cooked_on')
            ->whereKey($readiness->keys()->all());

        if ($readiness->isNotEmpty()) {
            // The ids are the database's own integers, so they're safe to write into the SQL.
            $query->orderByRaw('case '.$readiness
                ->groupBy(fn (Readiness $closest): int => $closest->rank(), preserveKeys: true)
                ->map(fn (Collection $tier, int $rank): string => 'when recipes.id in ('.$tier->keys()->map(fn (int $id): int => $id)->join(',').') then '.$rank)
                ->join(' ').' end');
        }

        return $query
            ->orderBy('cook_logs_max_cooked_on')
            ->orderBy('name');
    }
}
