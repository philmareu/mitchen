<?php

namespace App\Filament\Actions;

use App\Actions\ApplyKitchenUpdate;
use App\Actions\ReadKitchenUpdate;
use App\Ai\Agents\KitchenUpdateReader;
use App\Enums\ChangeKind;
use App\Enums\StorageLocation;
use App\Filament\Resources\InventoryItems\InventoryItemResource;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\ShoppingListItem;
use App\Models\Store;
use App\Support\ProposedChange;
use App\Support\PurchaseDetails;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Enums\Width;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\HtmlString;
use Livewire\Component;
use Throwable;

/**
 * "Bought 2 lbs chicken, out of milk, need eggs", or a photo of a receipt or a shelf: the
 * assistant reads it into inventory and shopping list changes, and nothing is saved until
 * the cook has checked them.
 */
class QuickUpdateAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'quickUpdate';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Quick update')
            ->icon(Heroicon::OutlinedSparkles)
            ->color('gray')
            ->disabled(fn (): bool => ! KitchenUpdateReader::isConfigured())
            ->tooltip(fn (): ?string => KitchenUpdateReader::isConfigured() ? null : 'Add ANTHROPIC_API_KEY to .env to use the assistant')
            ->modalHeading('Quick update')
            ->modalDescription('Tell Mitchen what changed, the way you\'d say it, or add photos of a grocery receipt, a shelf or the fridge. You\'ll check everything before it\'s saved.')
            ->modalWidth(Width::FiveExtraLarge)
            ->modalSubmitActionLabel('Save changes')
            ->steps([
                Step::make('note')
                    ->label('What changed?')
                    ->schema([
                        Textarea::make('note')
                            ->requiredWithout('photos')
                            ->rows(4)
                            ->maxLength(2_000)
                            ->autofocus()
                            ->placeholder('Bought 2 lbs chicken thighs and a jar of peanut butter. Out of milk, half a bag of rice left. Need eggs.'),
                        FileUpload::make('photos')
                            ->helperText('A grocery receipt, or a shelf, the fridge or the pantry to take stock of what\'s there. Several photos are fine. Add a note too if you like, such as where things went. Reading photos can take up to a minute.')
                            ->multiple()
                            ->maxFiles(4)
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                            ->maxSize(10_240)
                            // Resized in the browser to the largest size Claude reads at full detail.
                            ->automaticallyResizeImagesMode('contain')
                            ->automaticallyResizeImagesToWidth('2576')
                            ->automaticallyResizeImagesToHeight('2576')
                            ->automaticallyUpscaleImagesWhenResizing(false)
                            ->storeFiles(false),
                        Hidden::make('not_understood'),
                        Hidden::make('not_seen'),
                    ])
                    ->afterValidation(function (Get $get, Set $set, ReadKitchenUpdate $read): void {
                        $photos = collect(Arr::wrap($get('photos')))
                            ->filter(fn (mixed $file): bool => $file instanceof UploadedFile)
                            ->map(fn (UploadedFile $file): string => (string) $file->getRealPath())
                            ->values()
                            ->all();

                        $this->read((string) $get('note'), $photos, $set, $read);
                    }),
                Step::make('review')
                    ->label('Check the changes')
                    ->schema([
                        Callout::make('Skipped')
                            ->description(fn (Get $get): string => 'Left out: '.collect(Arr::wrap($get('not_understood')))->map(fn (mixed $text): string => '“'.$text.'”')->join(', '))
                            ->warning()
                            ->visible(fn (Get $get): bool => filled($get('not_understood'))),
                        self::purchaseFieldset()
                            ->visible(fn (Get $get): bool => $this->hasBoughtChanges($get)),
                        $this->changesRepeater(),
                        CheckboxList::make('bought_from_list')
                            ->label('Tick off the shopping list')
                            ->helperText('Ticked items come off the list.')
                            ->options(fn (): array => $this->shoppingListOptions())
                            ->columns(3)
                            ->visible(fn (Get $get): bool => $this->shoppingListOptions() !== [] && (filled($get('bought_from_list')) || $this->hasBoughtChanges($get))),
                        CheckboxList::make('used_up_items')
                            ->label('Not in the photos')
                            ->helperText('Tick anything that\'s gone. The rest stay as they are.')
                            ->options(fn (Get $get): array => $this->notSeenOptions(Arr::wrap($get('not_seen'))))
                            ->columns(2)
                            ->visible(fn (Get $get): bool => filled($get('not_seen'))),
                    ]),
            ])
            ->action(function (array $data, ApplyKitchenUpdate $apply, Component $livewire): void {
                $changes = array_map(ProposedChange::fromArray(...), array_values($data['changes'] ?? []));

                $usedUp = InventoryItem::query()
                    ->with('ingredient')
                    ->whereKey(array_map(intval(...), $data['used_up_items'] ?? []))
                    ->get()
                    ->map(ProposedChange::useUp(...))
                    ->all();

                $outcomes = $apply->handle(
                    [...$changes, ...$usedUp],
                    array_map(intval(...), $data['bought_from_list'] ?? []),
                    PurchaseDetails::fromArray($data['purchase'] ?? []),
                );

                $livewire->dispatch('inventory-updated');

                Notification::make()
                    ->title($outcomes === [] ? 'Nothing changed' : 'Inventory updated')
                    ->body(new HtmlString(collect($outcomes)->map(fn (string $line): string => e($line))->join('<br>')))
                    ->success()
                    ->send();
            });
    }

    /**
     * @param  list<string>  $photos
     */
    private function read(string $note, array $photos, Set $set, ReadKitchenUpdate $read): void
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

        if ($update->changes === [] && $update->boughtFromList === [] && $update->notSeen === []) {
            Notification::make()
                ->title('Nothing to update')
                ->body($photos === []
                    ? 'That note didn\'t mention anything in the kitchen changing. Try "bought…", "out of…", "half a … left" or "need…".'
                    : 'No groceries found in those photos. Try a sharper photo, with the whole receipt or the labels in frame.')
                ->warning()
                ->send();

            throw new Halt;
        }

        $set('changes', array_map(fn (ProposedChange $change): array => $change->toArray(), $update->changes));
        $set('not_understood', $update->notUnderstood);
        $set('bought_from_list', array_map(strval(...), $update->boughtFromList));
        $set('not_seen', $update->notSeen);
        $set('used_up_items', []);
        $set('purchase', $update->purchase->toArray());
    }

    /**
     * Where, when and for how much the shop was, for the purchase history.
     */
    public static function purchaseFieldset(): Fieldset
    {
        return Fieldset::make('Purchase')
            ->statePath('purchase')
            ->columns(3)
            ->schema([
                TextInput::make('store')
                    ->placeholder('Where you shopped')
                    ->maxLength(255)
                    ->datalist(fn (): array => Store::query()->orderBy('name')->pluck('name')->all()),
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
            ]);
    }

    private function hasBoughtChanges(Get $get): bool
    {
        return collect(Arr::wrap($get('changes')))
            ->contains(fn (mixed $change): bool => is_array($change) && $this->kind($change['kind'] ?? null) === ChangeKind::Bought);
    }

    private function changesRepeater(): Repeater
    {
        return Repeater::make('changes')
            ->hiddenLabel()
            ->table([
                TableColumn::make('Change')->width('9rem'),
                TableColumn::make('Ingredient')->markAsRequired(),
                TableColumn::make('How much')->width('10rem'),
                TableColumn::make('Price')->width('7rem'),
                TableColumn::make('Where')->width('9rem'),
                TableColumn::make('Use by')->width('10rem'),
                TableColumn::make('Ready on')->width('10rem'),
            ])
            ->compact()
            ->reorderable(false)
            ->addActionLabel('Add a change')
            ->schema([
                Hidden::make('inventory_item_id'),
                Hidden::make('category'),
                Hidden::make('from_note'),
                Select::make('kind')
                    ->options(ChangeKind::class)
                    ->default(ChangeKind::Bought)
                    ->selectablePlaceholder(false)
                    ->required()
                    ->live(),
                TextInput::make('ingredient')
                    ->required()
                    ->maxLength(255)
                    ->datalist(fn (): array => Ingredient::query()->orderBy('name')->pluck('name')->all())
                    ->belowContent(fn (Get $get): ?string => $this->describe($get)),
                TextInput::make('quantity')
                    ->placeholder(fn (Get $get): string => $this->kind($get('kind')) === ChangeKind::UsedUp ? '—' : 'e.g. 2 lb')
                    ->disabled(fn (Get $get): bool => $this->kind($get('kind')) === ChangeKind::UsedUp)
                    ->rules([InventoryItemResource::quantityRule()]),
                TextInput::make('price')
                    ->numeric()
                    ->minValue(0)
                    ->prefix('$')
                    ->disabled(fn (Get $get): bool => $this->kind($get('kind')) !== ChangeKind::Bought),
                Select::make('location')
                    ->options(StorageLocation::class)
                    ->default(StorageLocation::Pantry)
                    ->selectablePlaceholder(false)
                    ->disabled(fn (Get $get): bool => $this->kind($get('kind')) === ChangeKind::Need)
                    ->required(),
                DatePicker::make('expires_on')
                    ->disabled(fn (Get $get): bool => in_array($this->kind($get('kind')), [ChangeKind::UsedUp, ChangeKind::Need], true)),
                DatePicker::make('ready_on')
                    ->disabled(fn (Get $get): bool => in_array($this->kind($get('kind')), [ChangeKind::UsedUp, ChangeKind::Need], true)),
            ]);
    }

    /**
     * Where a change came from in the note, and whether it adds a new ingredient.
     */
    private function describe(Get $get): ?string
    {
        $name = trim((string) $get('ingredient'));
        $isNew = $name !== ''
            && $this->kind($get('kind')) !== ChangeKind::Need
            && Ingredient::query()->named($name)->doesntExist();

        return collect([
            filled($get('from_note')) ? '“'.$get('from_note').'”' : null,
            $isNew ? 'new ingredient' : null,
        ])->filter()->join(' · ') ?: null;
    }

    private function kind(mixed $kind): ?ChangeKind
    {
        return $kind instanceof ChangeKind ? $kind : ChangeKind::tryFrom((string) $kind);
    }

    /**
     * @return array<int, string>
     */
    private function shoppingListOptions(): array
    {
        return ShoppingListItem::query()
            ->toBuy()
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (ShoppingListItem $entry): array => [$entry->id => $entry->name.($entry->quantity ? " ({$entry->quantity})" : '')])
            ->all();
    }

    /**
     * @param  array<mixed>  $ids
     * @return array<int, string>
     */
    private function notSeenOptions(array $ids): array
    {
        return InventoryItem::query()
            ->with('ingredient')
            ->whereKey($ids)
            ->get()
            ->sortBy('ingredient.name')
            ->mapWithKeys(fn (InventoryItem $item): array => [$item->id => collect([
                $item->ingredient->name,
                $item->quantity->format() ?: null,
                $item->location->getLabel(),
            ])->filter()->join(' · ')])
            ->all();
    }
}
