<?php

namespace App\Filament\Pages;

use App\Filament\Actions\QuickUpdateAction;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    protected function getHeaderActions(): array
    {
        return [
            QuickUpdateAction::make(),
        ];
    }
}
