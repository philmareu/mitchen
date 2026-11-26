<?php

namespace App\Filament\Resources\Purchases\Pages;

use App\Filament\Resources\Purchases\PurchaseResource;
use App\Filament\Resources\Purchases\Widgets\SpendingOverview;
use App\Models\Purchase;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Width;

class ManagePurchases extends ManageRecords
{
    protected static string $resource = PurchaseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Add purchase')
                ->modalWidth(Width::FourExtraLarge)
                ->after(fn (Purchase $record) => $record->addUpMissingTotal()),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            SpendingOverview::class,
        ];
    }
}
