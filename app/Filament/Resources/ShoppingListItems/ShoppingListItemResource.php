<?php

namespace App\Filament\Resources\ShoppingListItems;

use App\Enums\IngredientCategory;
use App\Filament\Resources\ShoppingListItems\Pages\ManageShoppingListItems;
use App\Models\Builders\ShoppingListItemBuilder;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\ShoppingListItem;
use App\Models\Store;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\CheckboxColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;

/**
 * What to get on the next shop, grouped by aisle. Ticked off in the store, then put away
 * into the inventory; a receipt photo through Quick update does both at once. Ordering online
 * works the same way: find each thing in the store's online shop, add it to the cart there and
 * tick it off here.
 */
class ShoppingListItemResource extends Resource
{
    protected static ?string $model = ShoppingListItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static ?string $navigationLabel = 'Shopping list';

    protected static ?string $modelLabel = 'item';

    protected static ?string $pluralModelLabel = 'shopping list';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'shopping-list';

    public static function getNavigationBadge(): ?string
    {
        $count = ShoppingListItem::query()->toBuy()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('What')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('Eggs, paper towels…')
                    ->datalist(fn (): array => Ingredient::query()->orderBy('name')->pluck('name')->all()),
                TextInput::make('quantity')
                    ->label('How much?')
                    ->maxLength(100)
                    ->placeholder('A dozen, 2 lb…'),
                Select::make('category')
                    ->label('Aisle')
                    ->options(IngredientCategory::class)
                    ->default(IngredientCategory::Other)
                    ->selectablePlaceholder(false)
                    ->helperText('Taken from the ingredient when it\'s one you keep.'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (ShoppingListItemBuilder $query) => $query->with(['ingredient.inventoryItems', 'ingredient.lastPaid.purchase.store']))
            ->defaultGroup(Group::make('category')->label('Aisle'))
            ->defaultSort(fn (ShoppingListItemBuilder $query) => $query
                ->orderByRaw('case when bought_at is null then 0 else 1 end')
                ->orderBy('name'))
            ->paginated(false)
            ->emptyStateIcon(Heroicon::OutlinedShoppingCart)
            ->emptyStateHeading('Nothing to buy')
            ->emptyStateDescription('Add things yourself, bring in what\'s on the restock list, or tell Quick update "need eggs".')
            ->columns([
                CheckboxColumn::make('bought')
                    ->label('Got it')
                    ->width('1%')
                    ->getStateUsing(fn (ShoppingListItem $record): bool => $record->bought_at !== null)
                    ->updateStateUsing(fn (ShoppingListItem $record, ?bool $state): bool => $record->update(['bought_at' => $state ? now() : null])),
                TextColumn::make('name')
                    ->label('What')
                    ->searchable()
                    ->description(fn (ShoppingListItem $record): ?string => self::atHome($record))
                    ->extraAttributes(fn (ShoppingListItem $record): array => $record->bought_at === null ? [] : ['style' => 'text-decoration: line-through; opacity: 0.6']),
                TextColumn::make('quantity')
                    ->label('How much')
                    ->placeholder('—'),
            ])
            // No bulk actions: their selection checkboxes would sit right beside "Got it".
            // "Put away what you got" on the page takes ticked items off in one go.
            ->recordActions([
                ...self::findOnlineActions(),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    /**
     * A link for each store with an online shop that looks the entry up there, in a new tab.
     * Several stores share one menu. Nothing to find once it's ticked off (in the cart).
     *
     * @return list<Action|ActionGroup>
     */
    private static function findOnlineActions(): array
    {
        $actions = Store::query()->online()->orderBy('name')->get()
            ->filter(fn (Store $store): bool => $store->shopsOnline())
            ->map(fn (Store $store): Action => Action::make("findAt{$store->id}")
                ->label("Find at {$store->name}")
                ->icon(Heroicon::OutlinedMagnifyingGlass)
                ->color('gray')
                ->hidden(fn (ShoppingListItem $record): bool => $record->bought_at !== null)
                ->url(fn (ShoppingListItem $record): ?string => $store->searchUrl($record->name), shouldOpenInNewTab: true))
            ->values();

        if ($actions->count() < 2) {
            return $actions->all();
        }

        return [
            ActionGroup::make($actions->all())
                ->label('Find online')
                ->icon(Heroicon::OutlinedMagnifyingGlass)
                ->color('gray'),
        ];
    }

    /**
     * What's already in the kitchen, so you can tell in the store whether it's urgent, and what
     * it cost last time.
     */
    private static function atHome(ShoppingListItem $record): ?string
    {
        if ($record->ingredient === null) {
            return null;
        }

        $items = $record->ingredient->inventoryItems
            ->map(fn (InventoryItem $item): string => ($item->quantity->format() ?: 'Some').' in the '.mb_strtolower($item->location->getLabel()));
        $lastPaid = $record->ingredient->lastPaid;

        return collect([
            $items->isEmpty() ? 'None at home' : 'At home: '.$items->join(', '),
            $lastPaid === null ? null : "Last paid {$lastPaid->formattedPrice()} ({$lastPaid->whereAndWhen()})",
        ])->filter()->join(' · ');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageShoppingListItems::route('/'),
        ];
    }
}
