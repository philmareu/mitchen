<?php

namespace App\Filament\Resources\Purchases\Widgets;

use App\Models\Purchase;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;
use Livewire\Attributes\On;

class SpendingOverview extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    #[On('inventory-updated')]
    public function refreshAfterQuickUpdate(): void
    {
        // Re-rendering recalculates the stats.
    }

    protected function getStats(): array
    {
        $thisMonth = Purchase::query()->where('purchased_on', '>=', today()->startOfMonth());
        $lastMonth = Purchase::query()->whereBetween('purchased_on', [
            today()->subMonthNoOverflow()->startOfMonth(),
            today()->subMonthNoOverflow()->endOfMonth(),
        ]);

        $favorite = Purchase::query()
            ->where('purchased_on', '>=', today()->subDays(90))
            ->whereNotNull('store_id')
            ->with('store')
            ->get()
            ->groupBy('store_id')
            ->sortByDesc(fn ($purchases): int => $purchases->count())
            ->first();

        return [
            Stat::make('Spent this month', Number::currency((float) $thisMonth->sum('total')))
                ->description(self::trips($thisMonth->count()))
                ->icon(Heroicon::OutlinedCurrencyDollar),
            Stat::make('Last month', Number::currency((float) $lastMonth->sum('total')))
                ->description(self::trips($lastMonth->count())),
            Stat::make('Shop most at', $favorite?->first()?->store->name ?? '—')
                ->description($favorite === null
                    ? 'No stores in the last 90 days'
                    : self::trips($favorite->count()).' · '.Number::currency((float) $favorite->sum('total')).', last 90 days')
                ->icon(Heroicon::OutlinedBuildingStorefront),
        ];
    }

    private static function trips(int $count): string
    {
        return Number::format($count).' '.str('trip')->plural($count);
    }
}
