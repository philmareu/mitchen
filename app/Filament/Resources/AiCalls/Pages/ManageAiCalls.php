<?php

namespace App\Filament\Resources\AiCalls\Pages;

use App\Filament\Resources\AiCalls\AiCallResource;
use App\Filament\Resources\AiCalls\Widgets\AiUsageOverview;
use Filament\Resources\Pages\ManageRecords;

class ManageAiCalls extends ManageRecords
{
    protected static string $resource = AiCallResource::class;

    protected static ?string $title = 'AI usage';

    protected ?string $subheading = 'Token counts come from the provider; costs are estimates from the rates in config/ai.php.';

    protected function getHeaderWidgets(): array
    {
        return [
            AiUsageOverview::class,
        ];
    }
}
