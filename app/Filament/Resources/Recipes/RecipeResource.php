<?php

namespace App\Filament\Resources\Recipes;

use App\Filament\Resources\Equipment\EquipmentResource;
use App\Filament\Resources\Recipes\Pages\CreateRecipe;
use App\Filament\Resources\Recipes\Pages\EditRecipe;
use App\Filament\Resources\Recipes\Pages\ListRecipes;
use App\Filament\Resources\Recipes\Pages\ViewRecipe;
use App\Models\Builders\RecipeBuilder;
use App\Models\Equipment;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\RecipeEquipment;
use App\Models\RecipeIngredient;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn as EntryTableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * The cook's recipes, with what's in the kitchen for each. Added by hand or read from a
 * photo of the card with Quick add.
 */
class RecipeResource extends Resource
{
    protected static ?string $model = Recipe::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema(self::detailFields())
                    ->columns(2),
                Section::make('Ingredients')
                    ->schema([
                        self::ingredientsRepeater()
                            ->relationship()
                            ->orderColumn('sort'),
                    ]),
                Section::make('Equipment')
                    ->description('What not every kitchen has: a slow cooker, a Dutch oven, a tortilla press.')
                    ->schema([
                        self::equipmentRepeater()
                            ->relationship()
                            ->orderColumn('sort'),
                    ]),
                Section::make('Steps')
                    ->schema([self::stepsRepeater()]),
                Section::make('Photos')
                    ->schema([
                        EquipmentResource::photoUpload('photos')
                            ->hiddenLabel()
                            ->directory(Recipe::PHOTO_DIRECTORY)
                            ->reorderable()
                            ->panelLayout('grid')
                            ->imageEditor()
                            ->openable()
                            ->preventFilePathTampering()
                            ->helperText('The original card or page, front and back, kept with the recipe.'),
                    ]),
            ])
            ->columns(1);
    }

    /**
     * Also used by Quick add's review step.
     *
     * @return list<Component>
     */
    public static function detailFields(): array
    {
        return [
            TextInput::make('name')
                ->required()
                ->maxLength(255)
                ->placeholder('Grandma\'s beef stew'),
            TextInput::make('servings')
                ->label('Serves or makes')
                ->integer()
                ->minValue(1)
                ->maxValue(999)
                ->placeholder('4'),
            TextInput::make('source')
                ->maxLength(255)
                ->placeholder('Grandma\'s card, the back of the masa bag')
                ->columnSpanFull(),
            Textarea::make('notes')
                ->rows(2)
                ->placeholder('Freezes well. Dad likes extra garlic.')
                ->columnSpanFull(),
        ];
    }

    /**
     * Ingredient lines. Names that aren't in the catalog are added to it as one-offs when saved.
     *
     * @param  bool  $withAisle  Carry the aisle the assistant suggested for new ingredients.
     */
    public static function ingredientsRepeater(bool $withAisle = false): Repeater
    {
        return Repeater::make('ingredients')
            ->hiddenLabel()
            ->table([
                TableColumn::make('Amount')->width('9rem'),
                TableColumn::make('Ingredient')->markAsRequired(),
                TableColumn::make('Note')->width('13rem'),
                TableColumn::make('Optional')->width('6rem'),
            ])
            ->compact()
            ->defaultItems(0)
            ->addActionLabel('Add an ingredient')
            ->schema([
                TextInput::make('quantity')
                    ->maxLength(100),
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->datalist(fn (): array => Ingredient::query()->orderBy('name')->pluck('name')->all())
                    ->belowContent(fn (Get $get): ?string => filled($get('name')) && ! Ingredient::query()->named((string) $get('name'))->exists()
                        ? 'New: added to your ingredients'
                        : null),
                TextInput::make('note')
                    ->maxLength(255),
                Checkbox::make('is_optional'),
                ...($withAisle ? [Hidden::make('category')] : []),
            ]);
    }

    public static function equipmentRepeater(): Repeater
    {
        return Repeater::make('equipment')
            ->hiddenLabel()
            ->table([
                TableColumn::make('Equipment')->markAsRequired(),
            ])
            ->compact()
            ->defaultItems(0)
            ->addActionLabel('Add equipment')
            ->schema([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->datalist(fn (): array => Equipment::query()->orderBy('name')->pluck('name')->all())
                    ->belowContent(fn (Get $get): ?string => filled($get('name')) && ! Equipment::query()->named((string) $get('name'))->exists()
                        ? 'Not in your equipment'
                        : null),
            ]);
    }

    public static function stepsRepeater(): Repeater
    {
        return Repeater::make('steps')
            ->hiddenLabel()
            ->simple(
                Textarea::make('step')
                    ->required()
                    ->rows(2),
            )
            ->itemNumbers()
            ->defaultItems(0)
            ->addActionLabel('Add a step');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Ingredients')
                    ->description(fn (mixed $livewire): ?string => self::scaleDescription($livewire))
                    ->schema([
                        RepeatableEntry::make('ingredients')
                            ->hiddenLabel()
                            ->table([
                                EntryTableColumn::make('Amount')->width('9rem'),
                                EntryTableColumn::make('Ingredient'),
                                EntryTableColumn::make('In the kitchen'),
                            ])
                            ->schema([
                                TextEntry::make('quantity')
                                    ->label('Amount')
                                    ->state(fn (RecipeIngredient $record, mixed $livewire): ?string => $record->quantityFor(self::scale($livewire)))
                                    ->placeholder('—'),
                                TextEntry::make('name')
                                    ->label('Ingredient')
                                    ->weight('medium')
                                    ->belowContent(fn (RecipeIngredient $record): ?string => collect([
                                        $record->note,
                                        $record->is_optional ? 'optional' : null,
                                    ])->filter()->join(' · ') ?: null),
                                TextEntry::make('availability')
                                    ->label('In the kitchen')
                                    ->state(fn (RecipeIngredient $record): string => $record->availability()->getLabel())
                                    ->badge()
                                    ->color(fn (RecipeIngredient $record): string => $record->availability()->getColor())
                                    ->belowContent(fn (RecipeIngredient $record): ?string => collect([
                                        $record->whereItIs(),
                                        $record->isOnShoppingList() ? 'On the shopping list' : null,
                                    ])->filter()->join(' · ') ?: null),
                            ])
                            ->placeholder('No ingredients yet.'),
                    ])
                    ->columnSpan(['lg' => 2]),
                Section::make('Equipment')
                    ->schema([
                        RepeatableEntry::make('equipment')
                            ->hiddenLabel()
                            ->contained(false)
                            ->schema([
                                TextEntry::make('name')
                                    ->hiddenLabel()
                                    ->weight('medium'),
                                TextEntry::make('in_kitchen')
                                    ->hiddenLabel()
                                    ->state(fn (RecipeEquipment $record): string => $record->isInKitchen() ? 'Have it' : 'Don\'t have')
                                    ->badge()
                                    ->color(fn (RecipeEquipment $record): string => $record->isInKitchen() ? 'success' : 'danger'),
                            ])
                            ->columns(2)
                            ->placeholder('Nothing special.'),
                    ])
                    ->columnSpan(['lg' => 1]),
                Section::make('Steps')
                    ->schema([
                        TextEntry::make('steps')
                            ->hiddenLabel()
                            ->state(fn (Recipe $record): array => collect($record->steps ?? [])
                                ->values()
                                ->map(fn (string $step, int $index): string => ($index + 1).'. '.$step)
                                ->all())
                            ->listWithLineBreaks()
                            ->placeholder('No steps yet.'),
                    ])
                    ->columnSpan(['lg' => 2]),
                Section::make('About')
                    ->schema([
                        TextEntry::make('servings')
                            ->label('Serves or makes')
                            ->placeholder('—'),
                        TextEntry::make('source')
                            ->placeholder('—'),
                        TextEntry::make('notes')
                            ->placeholder('—'),
                        ImageEntry::make('photos')
                            ->imageHeight('10rem')
                            ->belowContent(fn (Recipe $record): ?HtmlString => self::photoLinks($record))
                            ->visible(fn (Recipe $record): bool => filled($record->photos)),
                    ])
                    ->columnSpan(['lg' => 1]),
            ])
            ->columns(['lg' => 3]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['ingredients.ingredient.inventoryItems', 'equipment']))
            ->recordUrl(fn (Recipe $record): string => self::getUrl('view', ['record' => $record]))
            ->defaultSort('name')
            ->paginated([25, 50, 100, 'all'])
            ->defaultPaginationPageOption(50)
            ->emptyStateIcon(Heroicon::OutlinedBookOpen)
            ->emptyStateHeading('No recipes yet')
            ->emptyStateDescription('Snap a recipe card or the back of a package with Quick add, or type one in.')
            ->columns([
                ImageColumn::make('photos')
                    ->label('')
                    ->imageHeight(48)
                    ->square()
                    ->limit(1),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Recipe $record): ?string => $record->source),
                TextColumn::make('ready')
                    ->label('In the kitchen')
                    ->state(fn (Recipe $record): string => ($count = count($record->missing())) === 0 ? 'Ready to cook' : "Missing {$count}")
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Ready to cook' ? 'success' : 'warning')
                    ->description(fn (Recipe $record): ?string => Str::limit(implode(', ', $record->missing()), 60) ?: null),
                TextColumn::make('servings')
                    ->label('Serves')
                    ->numeric()
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                Filter::make('ready_to_cook')
                    ->label('Ready to cook')
                    ->query(fn (RecipeBuilder $query) => $query->readyToCook()),
            ])
            ->recordActions([
                EditAction::make(),
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
            'index' => ListRecipes::route('/'),
            'create' => CreateRecipe::route('/create'),
            'view' => ViewRecipe::route('/{record}'),
            'edit' => EditRecipe::route('/{record}/edit'),
        ];
    }

    /**
     * How much the recipe page is scaled by; 1 anywhere else.
     */
    private static function scale(mixed $livewire): float
    {
        return $livewire instanceof ViewRecipe ? $livewire->scale() : 1.0;
    }

    private static function scaleDescription(mixed $livewire): ?string
    {
        $scale = self::scale($livewire);

        return $scale == 1 ? null : 'Scaled '.ViewRecipe::formatScale($scale).'. Amounts Mitchen can\'t read, like "a pinch", stay as written.';
    }

    /**
     * Links to the full-size photos, to read the card.
     */
    private static function photoLinks(Recipe $record): ?HtmlString
    {
        $links = collect($record->photos ?? [])
            ->values()
            ->map(fn (string $path, int $index): string => sprintf(
                '<a href="%s" target="_blank" rel="noopener" style="text-decoration: underline">Photo %d</a>',
                e(Storage::temporaryUrl($path, now()->addHour())),
                $index + 1,
            ));

        return $links->isEmpty() ? null : new HtmlString('Full size: '.$links->join(' · '));
    }
}
