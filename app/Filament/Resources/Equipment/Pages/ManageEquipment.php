<?php

namespace App\Filament\Resources\Equipment\Pages;

use App\Filament\Actions\QuickAddEquipmentAction;
use App\Filament\Resources\Equipment\EquipmentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageEquipment extends ManageRecords
{
    protected static string $resource = EquipmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            QuickAddEquipmentAction::make(),
            CreateAction::make()
                ->label('Add equipment')
                ->modalHeading('Add equipment'),
        ];
    }
}
