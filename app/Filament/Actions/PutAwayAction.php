<?php

namespace App\Filament\Actions;

use App\Actions\PutAwayShopping;
use App\Enums\ChangeKind;
use App\Enums\StorageLocation;
use App\Filament\Resources\InventoryItems\InventoryItemResource;
use App\Models\Ingredient;
use App\Models\ShoppingListItem;
use App\Support\ProposedChange;
use App\Support\PurchaseDetails;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\HtmlString;
use Livewire\Component;

/**
 * Back from the shop: what was ticked off goes into the inventory and the purchase history,
 * after the cook has checked how much, where and (optionally) what it cost, and comes off the
 * shopping list.
 */
class PutAwayAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'putAway';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Put away what you got')
            ->icon(Heroicon::OutlinedArchiveBoxArrowDown)
            ->color('gray')
            ->visible(fn (): bool => ShoppingListItem::query()->bought()->exists())
            ->modalHeading('Put away what you got')
            ->modalDescription(fn (PutAwayShopping $putAway): string => $this->description($putAway))
            ->modalWidth(Width::FourExtraLarge)
            ->modalSubmitActionLabel('Put away')
            ->fillForm(function (PutAwayShopping $putAway): array {
                $entries = $this->entries();

                return [
                    'changes' => array_values(array_map(fn (ProposedChange $change): array => $change->toArray(), $putAway->propose($entries))),
                    'entries' => $entries->modelKeys(),
                    'purchase' => (new PurchaseDetails)->toArray(),
                ];
            })
            ->schema([
                Hidden::make('entries'),
                QuickUpdateAction::purchaseFieldset(),
                Repeater::make('changes')
                    ->hiddenLabel()
                    ->table([
                        TableColumn::make('Ingredient')->markAsRequired(),
                        TableColumn::make('How much')->width('10rem'),
                        TableColumn::make('Price')->width('7rem'),
                        TableColumn::make('Where')->width('9rem'),
                        TableColumn::make('Use by')->width('10rem'),
                    ])
                    ->compact()
                    ->reorderable(false)
                    ->addActionLabel('Add something else')
                    ->schema([
                        Hidden::make('kind')->default(ChangeKind::Bought->value),
                        Hidden::make('category'),
                        Hidden::make('from_note'),
                        TextInput::make('ingredient')
                            ->required()
                            ->maxLength(255)
                            ->datalist(fn (): array => Ingredient::query()->orderBy('name')->pluck('name')->all())
                            ->belowContent(fn (Get $get): ?string => $this->describe($get)),
                        TextInput::make('quantity')
                            ->placeholder('e.g. 2 lb')
                            ->rules([InventoryItemResource::quantityRule()]),
                        TextInput::make('price')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('$'),
                        Select::make('location')
                            ->options(StorageLocation::class)
                            ->default(StorageLocation::Pantry)
                            ->selectablePlaceholder(false)
                            ->required(),
                        DatePicker::make('expires_on'),
                    ]),
            ])
            ->action(function (array $data, PutAwayShopping $putAway, Component $livewire): void {
                $outcomes = $putAway->handle(
                    array_map(ProposedChange::fromArray(...), array_values($data['changes'] ?? [])),
                    array_map(intval(...), Arr::wrap($data['entries'] ?? [])),
                    PurchaseDetails::fromArray($data['purchase'] ?? []),
                );

                $livewire->dispatch('inventory-updated');

                Notification::make()
                    ->title('Put away')
                    ->body(new HtmlString(collect($outcomes)->map(fn (string $line): string => e($line))->join('<br>')))
                    ->success()
                    ->send();
            });
    }

    /**
     * @return Collection<int, ShoppingListItem>
     */
    private function entries(): Collection
    {
        return ShoppingListItem::query()->bought()->with('ingredient.inventoryItems')->orderBy('name')->get();
    }

    private function description(PutAwayShopping $putAway): string
    {
        $entries = $this->entries();
        $cleared = $entries->except(array_keys($putAway->propose($entries)));

        return collect([
            'Check how much you got and where it\'s going. Everything you ticked comes off the list.',
            $cleared->isEmpty() ? null : $cleared->pluck('name')->join(', ', ' and ').' just '.($cleared->count() === 1 ? 'comes' : 'come').' off the list.',
        ])->filter()->join(' ');
    }

    /**
     * What the list said when it wasn't a quantity, and whether it adds a new ingredient.
     */
    private function describe(Get $get): ?string
    {
        $name = trim((string) $get('ingredient'));

        return collect([
            filled($get('from_note')) ? 'List said “'.$get('from_note').'”' : null,
            $name !== '' && Ingredient::query()->named($name)->doesntExist() ? 'new ingredient' : null,
        ])->filter()->join(' · ') ?: null;
    }
}
