<?php

namespace App\Filament\Resources\Ingredients;

use App\Enums\IngredientCategory;
use App\Enums\StockLevel;
use App\Filament\Resources\Ingredients\Pages\ManageIngredients;
use App\Filament\Resources\InventoryItems\InventoryItemResource;
use App\Models\Builders\IngredientBuilder;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Support\Quantity;
use App\Support\UnreadableQuantity;
use BackedEnum;
use Closure;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class IngredientResource extends Resource
{
    protected static ?string $model = Ingredient::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components(self::formFields());
    }

    /**
     * Also used to create an ingredient on the fly from the inventory form.
     *
     * @return list<Component>
     */
    public static function formFields(): array
    {
        return [
            TextInput::make('name')
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true),
            Select::make('category')
                ->options(IngredientCategory::class),
            Toggle::make('is_core')
                ->label('Core ingredient')
                ->helperText('A staple you always keep on hand. When it runs low it goes on the restock list. Leave off for one-offs bought for a single recipe.')
                ->live()
                ->columnSpanFull(),
            TextInput::make('restock_point')
                ->label('Restock when down to')
                ->placeholder('low')
                ->helperText('An amount such as "¼ jar", "2 cans" or "1 lb", or "out" to wait until it\'s gone. Blank means when it\'s low or out.')
                ->formatStateUsing(fn (mixed $record): ?string => $record instanceof Ingredient ? ($record->restock_point->format() ?: null) : null)
                ->live(debounce: 400)
                ->hint(fn (?string $state): ?string => InventoryItemResource::readsAs($state))
                ->visible(fn (Get $get): bool => (bool) $get('is_core'))
                ->rules([InventoryItemResource::quantityRule(), self::restockPointRule()])
                ->columnSpanFull(),
            Textarea::make('notes')
                ->rows(2)
                ->columnSpanFull(),
        ];
    }

    /**
     * "Full" would put the ingredient on the restock list whenever any of it was opened.
     *
     * @return Closure(): (Closure(string, mixed, Closure): void)
     */
    public static function restockPointRule(): Closure
    {
        return fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
            try {
                $point = Quantity::parse($value);
            } catch (UnreadableQuantity) {
                return;
            }

            if ($point->level === StockLevel::Full) {
                $fail('That would keep it on the restock list all the time. Try "about half", an amount, or leave it blank.');
            }
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['inventoryItems', 'lastPaid.purchase.store']))
            ->defaultSort('name')
            ->paginated([25, 50, 100, 'all'])
            ->defaultPaginationPageOption(50)
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                IconColumn::make('is_core')
                    ->label('Core')
                    ->boolean()
                    ->trueIcon(Heroicon::Star)
                    ->trueColor('primary')
                    ->falseIcon(Heroicon::OutlinedMinus)
                    ->falseColor('gray')
                    ->sortable(),
                TextColumn::make('category')
                    ->badge()
                    ->color('gray')
                    ->sortable(),
                TextColumn::make('restock_point')
                    ->label('Restock at')
                    ->state(fn (Ingredient $record): ?string => $record->is_core ? ($record->restock_point->format() ?: 'Low') : null)
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('on_hand')
                    ->label('On hand')
                    ->state(fn (Ingredient $record): array => $record->inventoryItems
                        ->map(fn (InventoryItem $item): string => ($item->quantity->format() ?: 'On hand').' · '.$item->location->getLabel())
                        ->all())
                    ->badge()
                    ->color(fn (Ingredient $record): string => $record->needsRestocking() ? 'danger' : 'gray')
                    ->placeholder(fn (Ingredient $record): string => $record->is_core ? 'None — restock' : 'None'),
                TextColumn::make('last_paid')
                    ->label('Last paid')
                    ->state(fn (Ingredient $record): ?string => $record->lastPaid?->formattedPrice())
                    ->description(fn (Ingredient $record): ?string => $record->lastPaid?->whereAndWhen())
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                TernaryFilter::make('is_core')
                    ->label('Core ingredients'),
                SelectFilter::make('category')
                    ->options(IngredientCategory::class),
                Filter::make('needs_restock')
                    ->label('Needs restock')
                    ->query(fn (IngredientBuilder $query) => $query->needsRestock()),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->modalDescription('This also removes it from your inventory.'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageIngredients::route('/'),
        ];
    }
}
