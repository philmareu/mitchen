<?php

namespace App\Filament\Resources\Recipes;

use App\Filament\Resources\Equipment\EquipmentResource;
use App\Filament\Resources\Recipes\Pages\CreateRecipe;
use App\Filament\Resources\Recipes\Pages\EditRecipe;
use App\Filament\Resources\Recipes\Pages\ListRecipes;
use App\Filament\Resources\Recipes\Pages\ViewRecipe;
use App\Filament\Resources\Recipes\RelationManagers\CookLogsRelationManager;
use App\Filament\Resources\Recipes\RelationManagers\VersionsRelationManager;
use App\Models\Builders\RecipeBuilder;
use App\Models\CookLog;
use App\Models\Equipment;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\RecipeEquipment;
use App\Models\RecipeIngredient;
use App\Models\RecipeSubstitute;
use App\Models\RecipeVariant;
use App\Support\RecipeDraft;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn as EntryTableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
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

    private const string VARIANTS_DESCRIPTION = 'Ways to make it you pick before cooking: mild or hot, plain or blueberry. Name one of the ingredients to change its amount or leave it out, or a new one to add it.';

    private const string SUBSTITUTES_DESCRIPTION = 'What to use when you\'re out of an ingredient: no buttermilk, use milk and lemon juice.';

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
                Section::make('Substitutes')
                    ->description(self::SUBSTITUTES_DESCRIPTION)
                    ->schema([
                        self::substitutesRepeater(relationship: true),
                    ]),
                Section::make('Variants')
                    ->description(self::VARIANTS_DESCRIPTION)
                    ->schema([
                        self::variantsRepeater(relationship: true),
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
                Section::make('Version')
                    ->description(fn (?Recipe $record): string => 'This is version '.$record?->version.'. Saving a change to the ingredients, substitutes, variants, equipment, steps or servings makes version '.($record?->version + 1).' and keeps this one in the history.')
                    ->schema([
                        Toggle::make('new_version')
                            ->label('Keep this version in the history')
                            ->helperText('Turn off to fix a mistake without making a new version.')
                            ->live()
                            ->dehydrated(false),
                        Textarea::make('version_note')
                            ->label('What changed and why')
                            ->rows(2)
                            ->maxLength(5_000)
                            ->placeholder('More salt: the last batch was bland.')
                            ->visible(fn (Get $get): bool => (bool) $get('new_version'))
                            ->dehydrated(false),
                        CheckboxList::make('used_notes')
                            ->label('Notes for next time this takes in')
                            ->helperText('They\'re marked as used in the new version.')
                            ->options(fn (?Recipe $record): array => $record?->waitingNotes()
                                ->mapWithKeys(fn (CookLog $log): array => [(string) $log->id => $log->summary()])
                                ->all() ?? [])
                            ->visible(fn (Get $get, ?Recipe $record): bool => $get('new_version') && $record?->waitingNotes()->isNotEmpty())
                            ->dehydrated(false),
                    ])
                    ->visibleOn('edit'),
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
     * The recipe as the assistant read or revised it, for the cook to check: Quick add's and
     * Update from notes' review steps.
     *
     * @return list<Component>
     */
    public static function draftFields(): array
    {
        return [
            Grid::make(2)
                ->schema(self::detailFields()),
            Section::make('Ingredients')
                ->compact()
                ->schema([self::ingredientsRepeater(withAisle: true)]),
            Section::make('Substitutes')
                ->description(self::SUBSTITUTES_DESCRIPTION)
                ->compact()
                ->schema([self::substitutesRepeater(withAisle: true)]),
            Section::make('Variants')
                ->description(self::VARIANTS_DESCRIPTION)
                ->compact()
                ->schema([self::variantsRepeater(withAisle: true)]),
            Section::make('Equipment')
                ->compact()
                ->schema([self::equipmentRepeater()]),
            Section::make('Steps')
                ->compact()
                ->schema([self::stepsRepeater()]),
        ];
    }

    /**
     * Put a draft into draftFields().
     */
    public static function fillDraft(Set $set, RecipeDraft $draft): void
    {
        foreach ($draft->toArray() as $field => $state) {
            $set($field, $state);
        }

        // A simple repeater's items each hold their value under the field's name.
        $set('steps', array_map(fn (string $step): array => ['step' => $step], $draft->steps));
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

    /**
     * Substitutes, each for one of the ingredients in the form, with lines of their own.
     *
     * @param  bool  $relationship  Save to the recipe's substitutes (the resource form).
     * @param  bool  $withAisle  Carry the aisle the assistant suggested for new ingredients.
     */
    public static function substitutesRepeater(bool $relationship = false, bool $withAisle = false): Repeater
    {
        $lines = Repeater::make('ingredients')
            ->label('Use')
            ->table([
                TableColumn::make('Amount')->width('9rem'),
                TableColumn::make('Ingredient')->markAsRequired(),
            ])
            ->compact()
            ->minItems(1)
            ->defaultItems(1)
            ->addActionLabel('Add an ingredient')
            ->schema([
                TextInput::make('quantity')
                    ->maxLength(100),
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->datalist(fn (): array => Ingredient::query()->orderBy('name')->pluck('name')->all()),
                ...($withAisle ? [Hidden::make('category')] : []),
            ]);

        $substitutes = Repeater::make('substitutes')
            ->hiddenLabel()
            ->defaultItems(0)
            ->addActionLabel('Add a substitute')
            ->itemLabel(fn (array $state): ?string => filled($state['instead_of'] ?? null) ? 'Instead of '.$state['instead_of'] : null)
            ->schema([
                Select::make('instead_of')
                    ->label('Instead of')
                    ->options(fn (Get $get): array => collect(Arr::wrap($get('../../ingredients')))
                        ->map(fn (mixed $line): string => is_array($line) ? trim((string) ($line['name'] ?? '')) : '')
                        ->filter()
                        ->unique()
                        ->mapWithKeys(fn (string $name): array => [$name => $name])
                        ->all())
                    ->required(),
                TextInput::make('note')
                    ->maxLength(255)
                    ->placeholder('Let it sit 5 minutes'),
                ($relationship ? $lines->relationship()->orderColumn('sort') : $lines)
                    ->columnSpanFull(),
            ])
            ->columns(2);

        return $relationship ? $substitutes->relationship()->orderColumn('sort') : $substitutes;
    }

    /**
     * Variants, each with its changes to the ingredients.
     *
     * @param  bool  $relationship  Save to the recipe's variants (the resource form).
     * @param  bool  $withAisle  Carry the aisle the assistant suggested for new ingredients.
     */
    public static function variantsRepeater(bool $relationship = false, bool $withAisle = false): Repeater
    {
        $changes = Repeater::make('ingredients')
            ->label('Changes to the ingredients')
            ->table([
                TableColumn::make('Amount')->width('9rem'),
                TableColumn::make('Ingredient')->markAsRequired(),
                TableColumn::make('Note')->width('13rem'),
                TableColumn::make('Leave out')->width('6rem'),
            ])
            ->compact()
            ->defaultItems(1)
            ->addActionLabel('Add a change')
            ->schema([
                TextInput::make('quantity')
                    ->maxLength(100),
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->datalist(fn (): array => Ingredient::query()->orderBy('name')->pluck('name')->all()),
                TextInput::make('note')
                    ->maxLength(255),
                Checkbox::make('is_left_out'),
                ...($withAisle ? [Hidden::make('category')] : []),
            ]);

        $variants = Repeater::make('variants')
            ->hiddenLabel()
            ->defaultItems(0)
            ->addActionLabel('Add a variant')
            ->itemLabel(fn (array $state): ?string => filled($state['name'] ?? null) ? (string) $state['name'] : null)
            ->schema([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('Hot'),
                Textarea::make('notes')
                    ->rows(1)
                    ->placeholder('Keep the seeds in.'),
                ($relationship ? $changes->relationship()->orderColumn('sort') : $changes)
                    ->columnSpanFull(),
            ])
            ->columns(2);

        return $relationship ? $variants->relationship()->orderColumn('sort') : $variants;
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
                    ->description(fn (mixed $livewire): ?string => self::ingredientsDescription($livewire))
                    ->schema([
                        RepeatableEntry::make('ingredients')
                            ->hiddenLabel()
                            ->state(fn (Recipe $record, mixed $livewire): Collection => $record->linesFor(self::variant($livewire)))
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
                                    ->belowContent(fn (RecipeIngredient $record, mixed $livewire): ?HtmlString => self::lineDetails($record, self::scale($livewire), self::variant($livewire))),
                                TextEntry::make('availability')
                                    ->label('In the kitchen')
                                    ->state(fn (RecipeIngredient $record): string => $record->isCoveredBySubstitute() ? 'Substitute on hand' : $record->availability()->getLabel())
                                    ->badge()
                                    ->color(fn (RecipeIngredient $record): string => $record->isCoveredBySubstitute() ? 'info' : $record->availability()->getColor())
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
                        TextEntry::make('version')
                            ->state(fn (Recipe $record): string => 'Version '.$record->version)
                            ->belowContent(fn (Recipe $record): ?string => $record->version > 1 ? 'Earlier versions are under Versions below.' : null),
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
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(Recipe::KITCHEN)
                ->withMax('cookLogs', 'cooked_on'))
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
                TextColumn::make('cook_logs_max_cooked_on')
                    ->label('Last made')
                    ->date('M j, Y')
                    ->placeholder('Not yet')
                    ->sortable(),
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

    public static function getRelations(): array
    {
        return [
            CookLogsRelationManager::class,
            VersionsRelationManager::class,
        ];
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

    /**
     * The variant the recipe page is showing; none anywhere else.
     */
    private static function variant(mixed $livewire): ?RecipeVariant
    {
        return $livewire instanceof ViewRecipe ? $livewire->variant() : null;
    }

    /**
     * The variant being made and its notes, and how much it's scaled.
     */
    private static function ingredientsDescription(mixed $livewire): ?string
    {
        $scale = self::scale($livewire);
        $variant = self::variant($livewire);

        return collect([
            $variant === null ? null : 'Making it '.$variant->name.(filled($variant->notes) ? ': '.rtrim($variant->notes, '.').'.' : '.'),
            $scale == 1 ? null : 'Scaled '.ViewRecipe::formatScale($scale).'. Amounts Mitchen can\'t read, like "a pinch", stay as written.',
        ])->filter()->join(' ') ?: null;
    }

    /**
     * Under an ingredient: its note, whether it's optional or the variant's, and what to use
     * instead.
     */
    private static function lineDetails(RecipeIngredient $line, float $scale, ?RecipeVariant $variant): ?HtmlString
    {
        $details = collect([
            collect([
                $line->note,
                $line->is_optional ? 'optional' : null,
                $variant !== null && $line->variant_id === $variant->id ? 'for '.$variant->name : null,
            ])->filter()->join(' · '),
            ...$line->substitutes()->map(fn (RecipeSubstitute $substitute): string => 'Or '.$substitute->describe($scale)
                .(filled($substitute->note) ? ' ('.$substitute->note.')' : '')),
        ])->filter();

        return $details->isEmpty() ? null : new HtmlString($details->map(fn (string $detail): string => e($detail))->join('<br>'));
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
