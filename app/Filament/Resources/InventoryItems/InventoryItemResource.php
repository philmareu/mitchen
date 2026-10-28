<?php

namespace App\Filament\Resources\InventoryItems;

use App\Actions\UseUpInventoryItem;
use App\Enums\StockLevel;
use App\Enums\StorageLocation;
use App\Filament\Resources\Ingredients\IngredientResource;
use App\Filament\Resources\InventoryItems\Pages\ManageInventoryItems;
use App\Models\Builders\InventoryItemBuilder;
use App\Models\InventoryItem;
use App\Support\Quantity;
use App\Support\UnreadableQuantity;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class InventoryItemResource extends Resource
{
    protected static ?string $model = InventoryItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static ?string $navigationLabel = 'Inventory';

    protected static ?string $pluralModelLabel = 'inventory';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'inventory';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('ingredient_id')
                    ->label('Ingredient')
                    ->relationship('ingredient', 'name')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->createOptionForm(IngredientResource::formFields()),
                Select::make('location')
                    ->options(StorageLocation::class)
                    ->default(StorageLocation::Pantry)
                    ->required(),
                self::quantityInput(),
                DatePicker::make('expires_on')
                    ->label('Use by'),
                TextInput::make('notes')
                    ->maxLength(255)
                    ->columnSpanFull(),
            ]);
    }

    /**
     * One free-text box for any kind of quantity, with a live reading of what it understood.
     */
    public static function quantityInput(): TextInput
    {
        return TextInput::make('quantity')
            ->label('How much?')
            ->placeholder('2 lb, half a jar, 3 cans, low…')
            ->helperText('An amount, a container, or just "low" / "almost out". Leave blank if you\'re not sure.')
            ->formatStateUsing(fn (?InventoryItem $record): ?string => $record?->quantity->format())
            ->live(debounce: 400)
            ->hint(fn (?string $state): ?string => self::readsAs($state))
            ->rules([self::quantityRule()]);
    }

    /**
     * A live reading of a typed quantity, shown as a field hint.
     */
    public static function readsAs(?string $state): ?string
    {
        try {
            $quantity = Quantity::parse($state);
        } catch (UnreadableQuantity) {
            return 'Not sure what that means yet';
        }

        return $quantity->isUnknown() ? null : 'Reads as: '.$quantity->format();
    }

    /**
     * Validates that the parser can read a typed quantity, failing with its explanation.
     *
     * @return Closure(): (Closure(string, mixed, Closure): void)
     */
    public static function quantityRule(): Closure
    {
        return fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
            try {
                Quantity::parse($value);
            } catch (UnreadableQuantity $exception) {
                $fail($exception->getMessage());
            }
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('ingredient'))
            ->defaultGroup('location')
            ->groups([
                Group::make('location')
                    ->label('Location'),
                Group::make('ingredient.category')
                    ->label('Category'),
            ])
            ->defaultSort('updated_at', 'desc')
            ->paginated([25, 50, 100, 'all'])
            ->defaultPaginationPageOption(50)
            ->columns([
                TextColumn::make('ingredient.name')
                    ->label('Ingredient')
                    ->searchable()
                    ->sortable(),
                IconColumn::make('ingredient.is_core')
                    ->label('Core')
                    ->boolean()
                    ->trueIcon(Heroicon::Star)
                    ->trueColor('primary')
                    ->falseIcon(Heroicon::OutlinedMinus)
                    ->falseColor('gray'),
                TextColumn::make('quantity')
                    ->label('How much')
                    ->state(fn (InventoryItem $record): ?string => $record->quantity->format() ?: null)
                    ->badge()
                    ->color(fn (InventoryItem $record): string => $record->stock_level?->getColor() ?? 'gray')
                    ->placeholder('On hand'),
                TextColumn::make('location')
                    ->badge()
                    ->color('gray')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('expires_on')
                    ->label('Use by')
                    ->date('M j')
                    ->color(fn (InventoryItem $record): ?string => match (true) {
                        $record->expires_on?->lt(today()) => 'danger',
                        $record->expires_on?->lte(today()->addDays(3)) => 'warning',
                        default => null,
                    })
                    ->sortable(),
                TextColumn::make('notes')
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('location')
                    ->options(StorageLocation::class),
                TernaryFilter::make('core')
                    ->label('Core ingredients')
                    ->queries(
                        true: fn (Builder $query) => $query->whereRelation('ingredient', 'is_core', true),
                        false: fn (Builder $query) => $query->whereRelation('ingredient', 'is_core', false),
                    ),
                Filter::make('running_low')
                    ->label('Low or out')
                    ->query(fn (InventoryItemBuilder $query) => $query->runningLow()),
            ])
            ->recordActions([
                EditAction::make()
                    ->label('Update'),
                ActionGroup::make([
                    Action::make('markLow')
                        ->label('Mark low')
                        ->icon(Heroicon::OutlinedArrowTrendingDown)
                        ->color('warning')
                        ->hidden(fn (InventoryItem $record): bool => $record->quantity->needsRestock())
                        ->action(fn (InventoryItem $record) => $record->update(['quantity' => StockLevel::Low])),
                    self::useUpAction(),
                    DeleteAction::make(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function useUpAction(): Action
    {
        return Action::make('useUp')
            ->label('Used up')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('danger')
            ->hidden(fn (InventoryItem $record): bool => $record->stock_level === StockLevel::Out)
            ->requiresConfirmation()
            ->modalHeading(fn (InventoryItem $record): string => "Used up the {$record->ingredient->name}?")
            ->modalDescription(fn (InventoryItem $record): string => $record->ingredient->is_core
                ? 'It\'s a core ingredient, so it stays in your inventory marked out and goes on the restock list.'
                : 'It isn\'t a core ingredient, so it will be removed from your inventory.')
            ->modalSubmitActionLabel('Used up')
            ->action(function (InventoryItem $record, UseUpInventoryItem $useUp): void {
                $name = $record->ingredient->name;
                $kept = $useUp->handle($record);

                Notification::make()
                    ->title($kept ? "{$name} is on the restock list" : "{$name} removed from inventory")
                    ->success()
                    ->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageInventoryItems::route('/'),
        ];
    }
}
