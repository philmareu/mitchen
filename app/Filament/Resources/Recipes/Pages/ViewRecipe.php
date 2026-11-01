<?php

namespace App\Filament\Resources\Recipes\Pages;

use App\Actions\AddToShoppingList;
use App\Enums\Availability;
use App\Filament\Actions\UpdateRecipeFromNotesAction;
use App\Filament\Resources\Recipes\RecipeResource;
use App\Filament\Resources\Recipes\RelationManagers\CookLogsRelationManager;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeVariant;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;

/**
 * @property Recipe $record
 */
class ViewRecipe extends ViewRecord
{
    protected static string $resource = RecipeResource::class;

    /**
     * How much to scale the amounts by, from the Scale action.
     */
    public float $scale = 1;

    private const array SCALES = ['0.25', '0.5', '1', '1.5', '2', '3'];

    /**
     * The id of the variant being made, from the Variant action; null for the recipe as written.
     */
    public ?int $variant = null;

    public function variant(): ?RecipeVariant
    {
        return $this->record->variants->firstWhere('id', $this->variant);
    }

    public function scale(): float
    {
        return in_array((string) $this->scale, self::SCALES, true) ? $this->scale : 1.0;
    }

    /**
     * "½×", "2×".
     */
    public static function formatScale(float $scale): string
    {
        return match ((string) $scale) {
            '0.25' => '¼×',
            '0.5' => '½×',
            '1.5' => '1½×',
            default => ((string) $scale).'×',
        };
    }

    public function getTitle(): string
    {
        return $this->record->name;
    }

    /**
     * How close the kitchen is to making it the way it's shown ("A quick shop: Limes"), and a
     * variant that's closer, if there is one.
     */
    public function getSubheading(): string
    {
        $shortfall = $this->record->shortfall($this->variant());
        $closest = $this->record->closestShortfall();
        $readiness = $shortfall->readiness();

        return collect([
            $readiness->getLabel().($shortfall->describe() === '' ? '.' : ': '.$shortfall->describe().'.'),
            $closest->variant !== null && $closest->readiness()->isCloserThan($readiness)
                ? $closest->readiness()->getLabel().' as '.$closest->variant->name.'.'
                : null,
        ])->filter()->join(' ');
    }

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load([
            ...Recipe::KITCHEN,
            'ingredients.ingredient.shoppingListItems',
            'variants.ingredients.ingredient.shoppingListItems',
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->variantAction(),
            $this->scaleAction(),
            $this->addToShoppingListAction(),
            $this->madeItAction(),
            UpdateRecipeFromNotesAction::make(),
            EditAction::make(),
            DeleteAction::make()
                ->modalDescription('Its photos are deleted too. The ingredients stay in your catalog.'),
        ];
    }

    /**
     * The cook log changed in its table: the Update from notes badge counts its notes.
     */
    #[On('cook-log-updated')]
    public function refreshCookLog(): void {}

    private function madeItAction(): Action
    {
        return Action::make('madeIt')
            ->label('Made it')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('gray')
            ->modalHeading('Made it')
            ->modalDescription('How did it turn out? You can add your thoughts later from the cook log, once the pickles have sat a few days.')
            ->modalWidth(Width::Large)
            ->modalSubmitActionLabel('Add to the cook log')
            ->schema(CookLogsRelationManager::fields($this->record))
            ->fillForm(fn (): array => ['cooked_on' => today()->toDateString(), 'variant' => $this->variant()?->name])
            ->action(function (array $data): void {
                $this->record->cookLogs()->create($data);

                Notification::make()
                    ->title('Added to the cook log')
                    ->success()
                    ->send();

                $this->dispatch('cook-log-updated');
            });
    }

    private function variantAction(): Action
    {
        return Action::make('variant')
            ->label(fn (): string => $this->variant() === null ? 'Variant' : 'Making '.$this->variant()->name)
            ->icon(Heroicon::OutlinedSwatch)
            ->color('gray')
            ->visible(fn (): bool => $this->record->variants->isNotEmpty())
            ->modalHeading('Which way are you making it?')
            ->modalDescription('The ingredients, what\'s on hand and the shopping list follow the variant.')
            ->modalWidth(Width::Large)
            ->modalSubmitActionLabel('Show it')
            ->fillForm(fn (): array => ['variant' => (string) ($this->variant()->id ?? 0)])
            ->schema([
                ToggleButtons::make('variant')
                    ->hiddenLabel()
                    ->options(fn (): array => [0 => 'As written'] + $this->record->variants->pluck('name', 'id')->all())
                    ->inline()
                    ->required(),
            ])
            ->action(function (array $data): void {
                $this->variant = ((int) $data['variant']) ?: null;
            });
    }

    private function scaleAction(): Action
    {
        return Action::make('scale')
            ->label(fn (): string => $this->scale() == 1 ? 'Scale' : 'Scaled '.self::formatScale($this->scale()))
            ->icon(Heroicon::OutlinedScale)
            ->color('gray')
            ->modalHeading('Scale the recipe')
            ->modalDescription('Amounts Mitchen can read are scaled; "a pinch" or "to taste" stay as written.')
            ->modalWidth(Width::Large)
            ->modalSubmitActionLabel('Scale')
            ->fillForm(fn (): array => ['scale' => (string) $this->scale()])
            ->schema([
                ToggleButtons::make('scale')
                    ->hiddenLabel()
                    ->options(fn (): array => collect(self::SCALES)
                        ->mapWithKeys(fn (string $scale): array => [$scale => $this->scaleLabel((float) $scale)])
                        ->all())
                    ->inline()
                    ->required(),
            ])
            ->action(function (array $data): void {
                $this->scale = (float) $data['scale'];
            });
    }

    private function scaleLabel(float $scale): string
    {
        $servings = $this->record->servings;

        if ($servings === null) {
            return self::formatScale($scale);
        }

        $scaled = $servings * $scale;

        return self::formatScale($scale).' · '.(floor($scaled) == $scaled ? (int) $scaled : round($scaled, 1));
    }

    private function addToShoppingListAction(): Action
    {
        return Action::make('addToShoppingList')
            ->label('Add to shopping list')
            ->icon(Heroicon::OutlinedShoppingCart)
            ->color('gray')
            ->disabled(fn (): bool => $this->record->ingredientsToBuy($this->variant())->isEmpty())
            ->tooltip(fn (): ?string => $this->record->ingredientsToBuy($this->variant())->isEmpty() ? 'Everything is on hand or on the list already' : null)
            ->modalHeading('Add to the shopping list')
            ->modalDescription('What\'s out or running low and not on the list yet. What\'s out is ticked, unless a substitute is on hand.')
            ->modalWidth(Width::Large)
            ->modalSubmitActionLabel('Add to list')
            ->fillForm(fn (): array => [
                'lines' => $this->record->ingredientsToBuy($this->variant())
                    ->filter(fn (RecipeIngredient $line): bool => ! $line->is_optional && $line->availability() === Availability::Out && ! $line->isCoveredBySubstitute())
                    ->map(fn (RecipeIngredient $line): string => (string) $line->id)
                    ->values()
                    ->all(),
            ])
            ->schema([
                CheckboxList::make('lines')
                    ->hiddenLabel()
                    ->options(fn (): array => $this->record->ingredientsToBuy($this->variant())
                        ->mapWithKeys(fn (RecipeIngredient $line): array => [(string) $line->id => $line->name])
                        ->all())
                    ->descriptions(fn (): array => $this->record->ingredientsToBuy($this->variant())
                        ->mapWithKeys(fn (RecipeIngredient $line): array => [(string) $line->id => collect([
                            $line->availability()->getLabel(),
                            $line->isCoveredBySubstitute() ? 'substitute on hand' : null,
                            $line->quantityFor($this->scale()),
                            $line->is_optional ? 'optional' : null,
                        ])->filter()->join(' · ')])
                        ->all())
                    ->required(),
            ])
            ->action(function (array $data, AddToShoppingList $add): void {
                $added = $add->fromRecipe($this->record, array_map(intval(...), $data['lines']), $this->scale(), $this->variant());

                Notification::make()
                    ->title('Added to the shopping list')
                    ->body(implode(', ', $added))
                    ->success()
                    ->send();

                $this->record->load(['ingredients.ingredient.shoppingListItems', 'variants.ingredients.ingredient.shoppingListItems']);
            });
    }
}
