<?php

namespace App\Filament\Resources\InventoryItems\Pages;

use App\Filament\Actions\QuickUpdateAction;
use App\Filament\Resources\InventoryItems\InventoryItemResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageInventoryItems extends ManageRecords
{
    protected static string $resource = InventoryItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            QuickUpdateAction::make(),
            CreateAction::make()
                ->label('Add to inventory')
                ->modalHeading('Add to inventory'),
        ];
    }
}
