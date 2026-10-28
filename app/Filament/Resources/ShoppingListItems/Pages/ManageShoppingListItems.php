<?php

namespace App\Filament\Resources\ShoppingListItems\Pages;

use App\Actions\AddToShoppingList;
use App\Enums\IngredientCategory;
use App\Filament\Actions\PutAwayAction;
use App\Filament\Actions\QuickUpdateAction;
use App\Filament\Resources\ShoppingListItems\ShoppingListItemResource;
use App\Models\Ingredient;
use App\Models\ShoppingListItem;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageShoppingListItems extends ManageRecords
{
    protected static string $resource = ShoppingListItemResource::class;

    protected static ?string $title = 'Shopping list';

    protected function getHeaderActions(): array
    {
        return [
            QuickUpdateAction::make(),
            Action::make('addRestockList')
                ->label(fn (): string => 'Add '.$this->restockCount().' from the restock list')
                ->icon(Heroicon::OutlinedPlusCircle)
                ->color('gray')
                ->visible(fn (): bool => $this->restockCount() > 0)
                ->action(function (AddToShoppingList $add): void {
                    $names = $add->restockList();

                    Notification::make()
                        ->title('Added '.collect($names)->join(', ', ' and '))
                        ->success()
                        ->send();
                }),
            PutAwayAction::make(),
            CreateAction::make()
                ->label('Add item')
                ->modalHeading('Add to the shopping list')
                ->using(fn (array $data, AddToShoppingList $add): ShoppingListItem => $add->handle(
                    $data['name'],
                    $data['quantity'] ?? null,
                    $data['category'] instanceof IngredientCategory ? $data['category'] : IngredientCategory::tryFrom((string) ($data['category'] ?? '')),
                )),
        ];
    }

    private function restockCount(): int
    {
        return Ingredient::query()->needsRestock()->notOnShoppingList()->count();
    }
}
