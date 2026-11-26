<?php

namespace App\Filament\Resources\Purchases;

use App\Enums\StockLevel;
use App\Enums\Unit;
use App\Filament\Resources\InventoryItems\InventoryItemResource;
use App\Filament\Resources\Purchases\Pages\ManagePurchases;
use App\Models\Ingredient;
use App\Models\Purchase;
use App\Support\Quantity;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Purchase history: every shop recorded by Put away on the shopping list, by an assistant
 * over the MCP server, or by hand, with what it cost.
 */
class PurchaseResource extends Resource
{
    protected static ?string $model = Purchase::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static ?int $navigationSort = 6;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(3)->schema([
                    Select::make('store_id')
                        ->label('Store')
                        ->relationship('store', 'name')
                        ->searchable()
                        ->preload()
                        ->createOptionForm([
                            TextInput::make('name')->required()->maxLength(255)->unique('stores', 'name'),
                        ]),
                    DatePicker::make('purchased_on')
                        ->label('Bought on')
                        ->default(today())
                        ->maxDate(today())
                        ->required(),
                    TextInput::make('total')
                        ->label('Total paid')
                        ->numeric()
                        ->minValue(0)
                        ->prefix('$')
                        ->placeholder('Adds up the prices'),
                ]),
                Repeater::make('items')
                    ->relationship()
                    ->label('What you bought')
                    ->table([
                        TableColumn::make('Item')->markAsRequired(),
                        TableColumn::make('How much')->width('11rem'),
                        TableColumn::make('Price')->width('8rem'),
                    ])
                    ->compact()
                    ->reorderable(false)
                    ->addActionLabel('Add an item')
                    // The quantity lives in three columns; the form edits it as text.
                    ->mutateRelationshipDataBeforeFillUsing(fn (array $data): array => [
                        ...$data,
                        'quantity' => Quantity::fromStorage(
                            $data['amount'] ?? null,
                            Unit::tryFrom((string) ($data['unit'] ?? '')),
                            StockLevel::tryFrom((string) ($data['stock_level'] ?? '')),
                        )->format() ?: null,
                    ])
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->datalist(fn (): array => Ingredient::query()->orderBy('name')->pluck('name')->all())
                            ->belowContent(fn (Get $get): ?string => filled($get('as_written')) ? '“'.$get('as_written').'”' : null),
                        TextInput::make('quantity')
                            ->placeholder('e.g. 2 lb')
                            ->rules([InventoryItemResource::quantityRule()]),
                        TextInput::make('price')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('$'),
                    ]),
            ])
            ->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['store', 'items']))
            ->defaultSort(fn (Builder $query) => $query->orderByDesc('purchased_on')->orderByDesc('id'))
            ->paginated([25, 50, 100])
            ->emptyStateIcon(Heroicon::OutlinedReceiptPercent)
            ->emptyStateHeading('No purchases yet')
            ->emptyStateDescription('Put away what you got from the shopping list, or add one by hand.')
            ->columns([
                TextColumn::make('purchased_on')
                    ->label('Date')
                    ->date('D, M j, Y')
                    ->sortable(),
                TextColumn::make('store.name')
                    ->label('Store')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('items')
                    ->label('What')
                    ->state(fn (Purchase $record): string => $record->items->pluck('name')->join(', '))
                    ->description(fn (Purchase $record): string => trans_choice('{1} :count item|[2,*] :count items', $record->items->count()))
                    ->limit(80)
                    ->wrap(),
                TextColumn::make('total')
                    ->money()
                    ->placeholder('—')
                    ->sortable()
                    ->summarize(Sum::make()->label('Total')->money()),
            ])
            ->filters([
                SelectFilter::make('store')
                    ->relationship('store', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('period')
                    ->options([
                        'this_month' => 'This month',
                        'last_month' => 'Last month',
                        '90_days' => 'Last 90 days',
                        'this_year' => 'This year',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'this_month' => $query->where('purchased_on', '>=', today()->startOfMonth()),
                        'last_month' => $query->whereBetween('purchased_on', [today()->subMonthNoOverflow()->startOfMonth(), today()->subMonthNoOverflow()->endOfMonth()]),
                        '90_days' => $query->where('purchased_on', '>=', today()->subDays(90)),
                        'this_year' => $query->where('purchased_on', '>=', today()->startOfYear()),
                        default => $query,
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->modalWidth(Width::FourExtraLarge)
                    ->after(fn (Purchase $record) => $record->addUpMissingTotal()),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManagePurchases::route('/'),
        ];
    }
}
