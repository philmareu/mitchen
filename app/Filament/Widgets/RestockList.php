<?php

namespace App\Filament\Widgets;

use App\Actions\AddToShoppingList;
use App\Actions\RestockIngredient;
use App\Enums\StorageLocation;
use App\Filament\Resources\InventoryItems\InventoryItemResource;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\ShoppingListItem;
use App\Support\Quantity;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\On;

/**
 * Core ingredients down to their restock point (by default low, out, or missing from the
 * inventory entirely), with a way to put them on the shopping list.
 */
class RestockList extends TableWidget
{
    protected static ?int $sort = 1;

    protected static ?string $heading = 'Restock list';

    #[On('inventory-updated')]
    public function refreshAfterQuickUpdate(): void
    {
        // Re-rendering re-runs the table query.
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Ingredient::query()
                ->needsRestock()
                ->with(['inventoryItems', 'shoppingListItems']))
            ->defaultSort('name')
            ->paginated([10, 25, 50])
            ->emptyStateIcon(Heroicon::OutlinedCheckBadge)
            ->emptyStateHeading('Nothing to restock')
            ->emptyStateDescription('Every core ingredient is on hand.')
            ->columns([
                TextColumn::make('name')
                    ->label('Ingredient')
                    ->description(fn (Ingredient $record): ?string => collect([
                        $record->restock_point->isUnknown() ? null : 'Restock at '.$record->restock_point->format(),
                        match (true) {
                            $record->shoppingListItems->contains(fn (ShoppingListItem $entry): bool => $entry->bought_at === null) => 'On the shopping list',
                            $record->isOnShoppingList() => 'Bought, not put away yet',
                            default => null,
                        },
                    ])->filter()->join(' · ') ?: null),
                TextColumn::make('status')
                    ->state(fn (Ingredient $record): array => $record->inventoryItems
                        ->map(fn (InventoryItem $item): string => $item->quantity->format().' · '.$item->location->getLabel())
                        ->all())
                    ->badge()
                    ->color('warning')
                    ->placeholder('Not stocked'),
            ])
            ->headerActions([
                Action::make('addAllToShoppingList')
                    ->label('Add all to shopping list')
                    ->icon(Heroicon::OutlinedShoppingCart)
                    ->color('gray')
                    ->visible(fn (): bool => Ingredient::query()->needsRestock()->notOnShoppingList()->exists())
                    ->action(function (AddToShoppingList $add): void {
                        Notification::make()
                            ->title('Added '.collect($add->restockList())->join(', ', ' and ').' to the shopping list')
                            ->success()
                            ->send();
                    }),
            ])
            ->recordActions([
                Action::make('addToShoppingList')
                    ->label('Add to shopping list')
                    ->icon(Heroicon::OutlinedShoppingCart)
                    ->iconButton()
                    ->tooltip('Add to shopping list')
                    ->color('gray')
                    ->hidden(fn (Ingredient $record): bool => $record->isOnShoppingList())
                    ->action(function (Ingredient $record, AddToShoppingList $add): void {
                        $add->handle($record);

                        Notification::make()
                            ->title("{$record->name} is on the shopping list")
                            ->success()
                            ->send();
                    }),
                Action::make('restock')
                    ->label('Restocked')
                    ->icon(Heroicon::OutlinedArchiveBoxArrowDown)
                    ->modalHeading(fn (Ingredient $record): string => "Restock {$record->name}")
                    ->modalSubmitActionLabel('Save')
                    ->modalWidth('md')
                    ->schema([
                        InventoryItemResource::quantityInput()
                            ->formatStateUsing(null)
                            ->label('How much did you get?'),
                        Select::make('location')
                            ->options(StorageLocation::class)
                            ->default(fn (Ingredient $record): StorageLocation => $record->inventoryItems->first()->location ?? StorageLocation::Pantry)
                            ->required(),
                    ])
                    ->action(function (Ingredient $record, array $data, RestockIngredient $restock): void {
                        $location = $data['location'] instanceof StorageLocation ? $data['location'] : StorageLocation::from($data['location']);

                        $restock->handle($record, Quantity::parse($data['quantity'] ?? null), $location);
                        // It's in the kitchen now, so it comes off the shopping list, ticked or not.
                        $record->shoppingListItems()->delete();

                        Notification::make()
                            ->title("{$record->name} restocked")
                            ->success()
                            ->send();
                    }),
            ]);
    }
}
