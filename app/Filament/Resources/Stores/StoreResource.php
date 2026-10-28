<?php

namespace App\Filament\Resources\Stores;

use App\Filament\Resources\Purchases\PurchaseResource;
use App\Filament\Resources\Stores\Pages\ManageStores;
use App\Models\Store;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Where the cook shops, with how often and how much. Stores are added by hand or the first
 * time a receipt or note names one.
 */
class StoreResource extends Resource
{
    protected static ?string $model = Store::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true)
                    ->helperText('Receipts are matched to stores by this name.'),
                Textarea::make('notes')
                    ->rows(3)
                    ->placeholder('Good for bulk rice, closed Mondays…'),
            ])
            ->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->withCount('purchases')
                ->withMax('purchases', 'purchased_on')
                ->withSum('purchases', 'total'))
            ->defaultSort('name')
            ->emptyStateIcon(Heroicon::OutlinedBuildingStorefront)
            ->emptyStateHeading('No stores yet')
            ->emptyStateDescription('Add the places you shop, or scan a receipt with Quick update and its store is added for you.')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Store $record): ?string => $record->notes),
                TextColumn::make('purchases_count')
                    ->label('Trips')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('purchases_max_purchased_on')
                    ->label('Last trip')
                    ->date('M j, Y')
                    ->placeholder('Never')
                    ->sortable(),
                TextColumn::make('purchases_sum_total')
                    ->label('Spent')
                    ->money()
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('purchases')
                    ->label('Purchases')
                    ->icon(Heroicon::OutlinedReceiptPercent)
                    ->color('gray')
                    ->url(fn (Store $record): string => PurchaseResource::getUrl(parameters: ['filters' => ['store' => ['value' => $record->id]]])),
                EditAction::make(),
                DeleteAction::make()
                    ->modalDescription('Its purchases stay in the history, without a store.'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageStores::route('/'),
        ];
    }
}
