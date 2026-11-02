<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\InventoryItems\InventoryItemResource;
use App\Models\InventoryItem;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\On;

/**
 * Anything with a use-by date in the next week (or already past it).
 */
class UseSoon extends TableWidget
{
    protected static ?int $sort = 2;

    protected static ?string $heading = 'Use soon';

    #[On('inventory-updated')]
    public function refreshAfterQuickUpdate(): void
    {
        // Re-rendering re-runs the table query.
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => InventoryItem::query()
                ->with('ingredient')
                ->useSoon())
            ->defaultSort('expires_on')
            ->paginated([10, 25, 50])
            ->emptyStateIcon(Heroicon::OutlinedCalendarDays)
            ->emptyStateHeading('Nothing about to expire')
            ->emptyStateDescription('Items with a use-by date in the next week show up here.')
            ->columns([
                TextColumn::make('ingredient.name')
                    ->label('Ingredient')
                    ->description(fn (InventoryItem $record): string => $record->quantity->format() ?: $record->location->getLabel()),
                TextColumn::make('expires_on')
                    ->label('Use by')
                    ->formatStateUsing(fn (InventoryItem $record): string => match (true) {
                        $record->expires_on->isToday() => 'Today',
                        $record->expires_on->isTomorrow() => 'Tomorrow',
                        $record->expires_on->isPast() => 'Past date',
                        default => 'In '.(int) today()->diffInDays($record->expires_on).' days',
                    })
                    ->description(fn (InventoryItem $record): string => $record->expires_on->format('D, M j'))
                    ->color(fn (InventoryItem $record): string => $record->expires_on->lt(today()) ? 'danger' : 'warning'),
            ])
            ->recordActions([
                InventoryItemResource::useUpAction(),
            ]);
    }
}
