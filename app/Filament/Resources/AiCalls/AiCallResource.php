<?php

namespace App\Filament\Resources\AiCalls;

use App\Filament\Resources\AiCalls\Pages\ManageAiCalls;
use App\Models\AiCall;
use App\Support\AiPricing;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;

/**
 * Read-only log of AI calls with their token usage and estimated cost.
 */
class AiCallResource extends Resource
{
    protected static ?string $model = AiCall::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    protected static ?string $navigationLabel = 'AI usage';

    protected static ?string $modelLabel = 'AI call';

    protected static ?string $slug = 'ai-usage';

    protected static ?int $navigationSort = 9;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('user'))
            ->defaultSort('created_at', 'desc')
            ->paginated([25, 50, 100])
            ->emptyStateIcon(Heroicon::OutlinedCpuChip)
            ->emptyStateHeading('No AI calls yet')
            ->emptyStateDescription('Every request to an AI model shows up here with its tokens and estimated cost.')
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->since()
                    ->dateTimeTooltip('M j, Y g:i:s a')
                    ->sortable(),
                TextColumn::make('agent')
                    ->label('Feature')
                    ->formatStateUsing(fn (AiCall $record): string => $record->feature()),
                TextColumn::make('model')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('input_tokens')
                    ->label('Tokens in')
                    ->numeric()
                    ->description(fn (AiCall $record): ?string => $record->cache_read_tokens > 0
                        ? Number::format($record->cache_read_tokens).' cached'
                        : null)
                    ->sortable(),
                TextColumn::make('output_tokens')
                    ->label('Tokens out')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('cost')
                    ->label('Est. cost')
                    ->formatStateUsing(fn (?float $state): string => AiPricing::format($state))
                    ->placeholder('No price')
                    ->sortable()
                    ->summarize(Sum::make()
                        ->label('Total')
                        ->formatStateUsing(fn (mixed $state): string => AiPricing::format((float) $state))),
                TextColumn::make('duration_ms')
                    ->label('Time')
                    ->formatStateUsing(fn (int $state): string => Number::format($state / 1000, precision: 1).' s')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('status')
                    ->state(fn (AiCall $record): string => $record->failed() ? 'Failed' : 'OK')
                    ->badge()
                    ->color(fn (AiCall $record): string => $record->failed() ? 'danger' : 'success')
                    ->tooltip(fn (AiCall $record): ?string => $record->error),
                TextColumn::make('user.name')
                    ->label('By')
                    ->placeholder('System')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('period')
                    ->options([
                        'today' => 'Today',
                        '7_days' => 'Last 7 days',
                        'this_month' => 'This month',
                        '30_days' => 'Last 30 days',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'today' => $query->where('created_at', '>=', today()),
                        '7_days' => $query->where('created_at', '>=', now()->subDays(7)),
                        'this_month' => $query->where('created_at', '>=', now()->startOfMonth()),
                        '30_days' => $query->where('created_at', '>=', now()->subDays(30)),
                        default => $query,
                    }),
                SelectFilter::make('agent')
                    ->label('Feature')
                    ->options(fn (): array => AiCall::query()->distinct()->orderBy('agent')->pluck('agent')
                        ->mapWithKeys(fn (string $agent): array => [$agent => (new AiCall(['agent' => $agent]))->feature()])
                        ->all()),
                SelectFilter::make('model')
                    ->options(fn (): array => AiCall::query()->whereNotNull('model')->distinct()->orderBy('model')->pluck('model', 'model')->all()),
                Filter::make('failed')
                    ->label('Failed only')
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('error')),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAiCalls::route('/'),
        ];
    }
}
