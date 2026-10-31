<?php

namespace App\Filament\Actions;

use App\Actions\ApplyEquipmentUpdate;
use App\Actions\ReadEquipmentUpdate;
use App\Ai\Agents\EquipmentReader;
use App\Enums\EquipmentCategory;
use App\Enums\EquipmentChangeKind;
use App\Filament\Resources\Equipment\EquipmentResource;
use App\Models\Equipment;
use App\Support\ProposedEquipment;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Image;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Enums\Width;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\HtmlString;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

/**
 * Photos of a stand mixer, a drawer of utensils or a shelf of pans, and/or a note ("two half
 * sheet pans, the blender died"): the assistant reads them into equipment to add, update or
 * remove, and nothing is saved until the cook has checked it. Unlike Quick update, the
 * photos are kept, with each piece they show.
 */
class QuickAddEquipmentAction extends Action
{
    private const int MAX_PHOTOS = 4;

    public static function getDefaultName(): ?string
    {
        return 'quickAddEquipment';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Quick add')
            ->icon(Heroicon::OutlinedSparkles)
            ->color('gray')
            ->disabled(fn (): bool => ! EquipmentReader::isConfigured())
            ->tooltip(fn (): ?string => EquipmentReader::isConfigured() ? null : 'Add ANTHROPIC_API_KEY to .env to use the assistant')
            ->modalHeading('Quick add equipment')
            ->modalDescription('Add photos of your equipment, a single piece or a whole drawer or shelf, and tell Mitchen anything the photos don\'t show. You\'ll check everything before it\'s saved.')
            ->modalWidth(Width::SevenExtraLarge)
            ->modalSubmitActionLabel('Save equipment')
            ->steps([
                Step::make('photos')
                    ->label('What do you have?')
                    ->schema([
                        EquipmentResource::photoUpload('photos')
                            ->maxFiles(self::MAX_PHOTOS)
                            ->panelLayout('grid')
                            ->helperText('One piece per photo makes the best reference picture. A drawer or shelf works too: each piece gets its own copy to crop later. Reading photos can take up to a minute.')
                            ->storeFiles(false),
                        Textarea::make('note')
                            ->requiredWithout('photos')
                            ->rows(3)
                            ->maxLength(2_000)
                            ->placeholder('Lodge 10 inch cast iron skillet, two half sheet pans and a potato ricer. The old blender died.'),
                        Hidden::make('not_understood'),
                    ])
                    ->afterValidation(function (Get $get, Set $set, ReadEquipmentUpdate $read): void {
                        $photos = array_map(fn (UploadedFile $file): string => (string) $file->getRealPath(), $this->uploadedPhotos($get('photos')));

                        $this->read((string) $get('note'), $photos, $set, $read);
                    }),
                Step::make('review')
                    ->label('Check the equipment')
                    ->schema([
                        Callout::make('Skipped')
                            ->description(fn (Get $get): string => 'Left out: '.collect(Arr::wrap($get('not_understood')))->map(fn (mixed $text): string => '“'.$text.'”')->join(', '))
                            ->warning()
                            ->visible(fn (Get $get): bool => filled($get('not_understood'))),
                        $this->photoPreviews(),
                        $this->piecesRepeater(),
                    ]),
            ])
            ->action(function (array $data, ApplyEquipmentUpdate $apply): void {
                $outcomes = $apply->handle(
                    array_map(ProposedEquipment::fromArray(...), array_values($data['pieces'] ?? [])),
                    $this->uploadedPhotos($data['photos'] ?? []),
                );

                Notification::make()
                    ->title($outcomes === [] ? 'Nothing changed' : 'Equipment updated')
                    ->body(new HtmlString(collect($outcomes)->map(fn (string $line): string => e($line))->join('<br>')))
                    ->success()
                    ->send();
            });
    }

    /**
     * @param  list<string>  $photos
     */
    private function read(string $note, array $photos, Set $set, ReadEquipmentUpdate $read): void
    {
        try {
            $update = $read->handle($note, $photos);
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()
                ->title('The assistant couldn\'t read that right now')
                ->body('Check that ANTHROPIC_API_KEY is set and try again in a moment. The usage page has the error.')
                ->danger()
                ->send();

            throw new Halt;
        }

        if ($update->pieces === []) {
            Notification::make()
                ->title('No equipment found')
                ->body($photos === []
                    ? 'That note didn\'t mention any kitchen equipment. Try "got a 7 qt Dutch oven" or "the blender died".'
                    : 'No kitchen equipment found in those photos. Try a sharper photo with the piece in full view.')
                ->warning()
                ->send();

            throw new Halt;
        }

        $set('pieces', array_map(fn (ProposedEquipment $piece): array => $piece->toArray(), $update->pieces));
        $set('not_understood', $update->notUnderstood);
    }

    /**
     * The attached photos, numbered for the Photos column.
     */
    private function photoPreviews(): Grid
    {
        return Grid::make(self::MAX_PHOTOS)
            ->schema(array_map(fn (int $index): Group => Group::make([
                Text::make('Photo '.($index + 1)),
                Image::make(
                    url: fn (Get $get): string => $this->previewUrl($this->uploadedPhotos($get('photos'))[$index] ?? null),
                    alt: 'Photo '.($index + 1),
                )->imageHeight('6rem'),
            ])->visible(fn (Get $get): bool => isset($this->uploadedPhotos($get('photos'))[$index])), range(0, self::MAX_PHOTOS - 1)))
            ->visible(fn (Get $get): bool => $this->uploadedPhotos($get('photos')) !== []);
    }

    private function piecesRepeater(): Repeater
    {
        $isGone = fn (Get $get): bool => $this->kind($get('kind')) === EquipmentChangeKind::Gone;

        return Repeater::make('pieces')
            ->hiddenLabel()
            ->table([
                TableColumn::make('Change')->width('9rem'),
                TableColumn::make('Name')->markAsRequired(),
                TableColumn::make('Category')->width('11rem'),
                TableColumn::make('Brand and model')->width('11rem'),
                TableColumn::make('Size')->width('7rem'),
                TableColumn::make('How many')->width('6rem'),
                TableColumn::make('Photos')->width('11rem'),
            ])
            ->compact()
            ->reorderable(false)
            ->addActionLabel('Add a piece')
            ->schema([
                Hidden::make('equipment_id'),
                Hidden::make('from_note'),
                Select::make('kind')
                    ->options(EquipmentChangeKind::class)
                    ->default(EquipmentChangeKind::Added)
                    ->selectablePlaceholder(false)
                    ->required()
                    ->live(),
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->datalist(fn (): array => Equipment::query()->orderBy('name')->pluck('name')->unique()->values()->all())
                    ->belowContent(fn (Get $get): ?string => $this->describe($get)),
                Select::make('category')
                    ->options(EquipmentCategory::class)
                    ->default(EquipmentCategory::Other)
                    ->selectablePlaceholder(false)
                    ->disabled($isGone),
                TextInput::make('brand')
                    ->maxLength(255)
                    ->disabled($isGone),
                TextInput::make('size')
                    ->maxLength(255)
                    ->disabled($isGone),
                TextInput::make('count')
                    ->integer()
                    ->minValue(1)
                    ->maxValue(999)
                    ->default(1)
                    ->required()
                    ->disabled($isGone),
                Select::make('photos')
                    ->multiple()
                    ->options(fn (Get $get): array => collect($this->uploadedPhotos($get('../../photos')))
                        ->keys()
                        ->mapWithKeys(fn (int $index): array => [(string) $index => 'Photo '.($index + 1)])
                        ->all())
                    ->placeholder('None')
                    ->disabled(fn (Get $get): bool => $isGone($get) || $this->uploadedPhotos($get('../../photos')) === []),
            ]);
    }

    /**
     * Where a piece came from in the note or photo, and whether one of that name is listed already.
     */
    private function describe(Get $get): ?string
    {
        $name = trim((string) $get('name'));
        $isListed = $name !== ''
            && $this->kind($get('kind')) === EquipmentChangeKind::Added
            && Equipment::query()->named($name)->exists();

        return collect([
            filled($get('from_note')) ? '“'.$get('from_note').'”' : null,
            $isListed ? 'you have one listed already' : null,
        ])->filter()->join(' · ') ?: null;
    }

    private function kind(mixed $kind): ?EquipmentChangeKind
    {
        return $kind instanceof EquipmentChangeKind ? $kind : EquipmentChangeKind::tryFrom((string) $kind);
    }

    /**
     * @return list<UploadedFile>
     */
    private function uploadedPhotos(mixed $state): array
    {
        return collect(Arr::wrap($state))
            ->filter(fn (mixed $file): bool => $file instanceof UploadedFile)
            ->values()
            ->all();
    }

    private function previewUrl(?UploadedFile $photo): string
    {
        return $photo instanceof TemporaryUploadedFile && $photo->isPreviewable() ? $photo->temporaryUrl() : '';
    }
}
