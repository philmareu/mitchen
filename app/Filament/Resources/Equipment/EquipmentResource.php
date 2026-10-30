<?php

namespace App\Filament\Resources\Equipment;

use App\Enums\EquipmentCategory;
use App\Filament\Resources\Equipment\Pages\ManageEquipment;
use App\Models\Equipment;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * The kitchen's equipment, from a stand mixer down to a ladle, with photos of the cook's
 * own pieces. Added by hand or read from photos with Quick add.
 */
class EquipmentResource extends Resource
{
    protected static ?string $model = Equipment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('Cast iron skillet'),
                Select::make('category')
                    ->options(EquipmentCategory::class),
                TextInput::make('brand')
                    ->label('Brand and model')
                    ->maxLength(255)
                    ->placeholder('Lodge'),
                TextInput::make('size')
                    ->maxLength(255)
                    ->placeholder('10 in, 5 qt, 9×13 in'),
                TextInput::make('count')
                    ->label('How many')
                    ->integer()
                    ->minValue(1)
                    ->maxValue(999)
                    ->default(1)
                    ->required(),
                self::photoUpload('photos')
                    ->directory(Equipment::PHOTO_DIRECTORY)
                    ->reorderable()
                    ->panelLayout('grid')
                    ->imageEditor()
                    ->openable()
                    ->preventFilePathTampering()
                    ->helperText('Photos of your own piece, kept as a reference. To crop a photo of a whole drawer down to this piece, use its edit button.')
                    ->columnSpanFull(),
                Textarea::make('notes')
                    ->rows(2)
                    ->placeholder('Top drawer by the stove. Bowl doesn\'t go in the dishwasher.')
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    /**
     * Photos of equipment, resized in the browser to the largest size Claude reads at full detail.
     * Also used by Quick add.
     */
    public static function photoUpload(string $name): FileUpload
    {
        return FileUpload::make($name)
            ->multiple()
            ->image()
            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->maxSize(10_240)
            ->automaticallyResizeImagesMode('contain')
            ->automaticallyResizeImagesToWidth('2576')
            ->automaticallyResizeImagesToHeight('2576')
            ->automaticallyUpscaleImagesWhenResizing(false);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->paginated([25, 50, 100, 'all'])
            ->defaultPaginationPageOption(50)
            ->emptyStateIcon(Heroicon::OutlinedWrenchScrewdriver)
            ->emptyStateHeading('No equipment yet')
            ->emptyStateDescription('Snap a drawer of utensils, a shelf of pans or your stand mixer with Quick add, or add a piece by hand.')
            ->columns([
                ImageColumn::make('photos')
                    ->label('')
                    ->imageHeight(48)
                    ->square()
                    ->stacked()
                    ->limit(3)
                    ->limitedRemainingText(),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Equipment $record): ?string => $record->details()),
                TextColumn::make('category')
                    ->badge()
                    ->color('gray')
                    ->sortable(),
                TextColumn::make('count')
                    ->label('How many')
                    ->numeric()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('notes')
                    ->limit(60)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->options(EquipmentCategory::class),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->modalDescription('Its photos are deleted too.'),
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
            'index' => ManageEquipment::route('/'),
        ];
    }
}
