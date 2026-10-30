<?php

namespace App\Filament\Resources\Recipes\Pages;

use App\Actions\AddToShoppingList;
use App\Enums\Availability;
use App\Filament\Resources\Recipes\RecipeResource;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
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

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load([
            'ingredients.ingredient.inventoryItems',
            'ingredients.ingredient.shoppingListItems',
            'equipment',
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->scaleAction(),
            $this->addToShoppingListAction(),
            EditAction::make(),
            DeleteAction::make()
                ->modalDescription('Its photos are deleted too. The ingredients stay in your catalog.'),
        ];
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
            ->disabled(fn (): bool => $this->record->ingredientsToBuy()->isEmpty())
            ->tooltip(fn (): ?string => $this->record->ingredientsToBuy()->isEmpty() ? 'Everything is on hand or on the list already' : null)
            ->modalHeading('Add to the shopping list')
            ->modalDescription('What\'s out or running low and not on the list yet.')
            ->modalWidth(Width::Large)
            ->modalSubmitActionLabel('Add to list')
            ->fillForm(fn (): array => [
                'lines' => $this->record->ingredientsToBuy()
                    ->filter(fn (RecipeIngredient $line): bool => ! $line->is_optional && $line->availability() === Availability::Out)
                    ->map(fn (RecipeIngredient $line): string => (string) $line->id)
                    ->values()
                    ->all(),
            ])
            ->schema([
                CheckboxList::make('lines')
                    ->hiddenLabel()
                    ->options(fn (): array => $this->record->ingredientsToBuy()
                        ->mapWithKeys(fn (RecipeIngredient $line): array => [(string) $line->id => $line->name])
                        ->all())
                    ->descriptions(fn (): array => $this->record->ingredientsToBuy()
                        ->mapWithKeys(fn (RecipeIngredient $line): array => [(string) $line->id => collect([
                            $line->availability()->getLabel(),
                            $line->quantityFor($this->scale()),
                            $line->is_optional ? 'optional' : null,
                        ])->filter()->join(' · ')])
                        ->all())
                    ->required(),
            ])
            ->action(function (array $data, AddToShoppingList $add): void {
                $added = $add->fromRecipe($this->record, array_map(intval(...), $data['lines']), $this->scale());

                Notification::make()
                    ->title('Added to the shopping list')
                    ->body(implode(', ', $added))
                    ->success()
                    ->send();

                $this->record->load(['ingredients.ingredient.shoppingListItems']);
            });
    }
}
