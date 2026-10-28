<?php

namespace App\Filament\Resources\AiCalls\Widgets;

use App\Models\AiCall;
use App\Support\AiPricing;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

class AiUsageOverview extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $thisMonth = AiCall::query()->where('created_at', '>=', now()->startOfMonth());
        $lastThirtyDays = AiCall::query()->where('created_at', '>=', now()->subDays(30));
        $succeeded = (clone $lastThirtyDays)->whereNull('error');
        $failures = (clone $lastThirtyDays)->whereNotNull('error')->count();
        $firstCall = AiCall::query()->min('created_at');

        return [
            Stat::make('Spent this month', AiPricing::format((float) $thisMonth->sum('cost')))
                ->description(sprintf(
                    '%s %s · %s tokens',
                    Number::format($thisMonth->count()),
                    str('call')->plural($thisMonth->count()),
                    Number::abbreviate((int) $thisMonth->sum('input_tokens') + (int) $thisMonth->sum('output_tokens'), maxPrecision: 1),
                ))
                ->icon(Heroicon::OutlinedCurrencyDollar),
            Stat::make('Spent all time', AiPricing::format((float) AiCall::query()->sum('cost')))
                ->description($firstCall === null ? 'No calls yet' : 'Since '.now()->parse($firstCall)->format('M j, Y')),
            Stat::make('Typical call', AiPricing::format((float) $succeeded->avg('cost')))
                ->description(sprintf(
                    '%s s · %s tokens, last 30 days',
                    Number::format(((float) $succeeded->avg('duration_ms')) / 1000, maxPrecision: 1),
                    Number::format((int) round((float) $succeeded->avg('input_tokens') + (float) $succeeded->avg('output_tokens'))),
                )),
            Stat::make('Failed calls', Number::format($failures))
                ->description('Last 30 days')
                ->color($failures > 0 ? 'danger' : 'gray'),
        ];
    }
}
