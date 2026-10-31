<?php

namespace App\Filament\Resources\Recipes\RelationManagers;

use App\Filament\Resources\Recipes\Pages\ViewRecipe;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The recipe's earlier versions, on the recipe page: when each change was made, why, what
 * changed, and the recipe as it was before.
 */
class VersionsRelationManager extends RelationManager
{
    protected static string $relationship = 'versions';

    protected static ?string $title = 'Versions';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $pageClass === ViewRecipe::class;
    }

    public function table(Table $table): Table
    {
        /** @var Recipe $recipe */
        $recipe = $this->getOwnerRecord();

        return $table
            ->description('This is version '.$recipe->version.'. Version 1 is the recipe as first added, on '.$recipe->created_at?->format('M j, Y').'.')
            ->emptyStateIcon(Heroicon::OutlinedClock)
            ->emptyStateHeading('No changes yet')
            ->emptyStateDescription('Changing the ingredients, substitutes, variants, equipment, steps or servings keeps the old version here.')
            ->columns([
                TextColumn::make('number')
                    ->label('Version')
                    ->formatStateUsing(fn (int $state): string => 'Version '.$state)
                    ->description(fn (RecipeVersion $record): string => $record->created_at?->format('M j, Y') ?? ''),
                TextColumn::make('note')
                    ->label('Why')
                    ->wrap()
                    ->placeholder('—'),
                TextColumn::make('changes')
                    ->label('What changed')
                    ->listWithLineBreaks()
                    ->bulleted()
                    ->wrap(),
            ])
            ->recordActions([
                Action::make('previous')
                    ->label(fn (RecipeVersion $record): string => 'See version '.($record->number - 1))
                    ->icon(Heroicon::OutlinedEye)
                    ->color('gray')
                    ->modalHeading(fn (RecipeVersion $record): string => 'Version '.($record->number - 1))
                    ->modalDescription('The recipe before this change.')
                    ->modalWidth(Width::TwoExtraLarge)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->schema(self::snapshotEntries()),
            ]);
    }

    /**
     * The recipe as a version had it (RecipeVersion::$previous).
     *
     * @return list<TextEntry>
     */
    public static function snapshotEntries(): array
    {
        return [
            TextEntry::make('previous.servings')
                ->label('Serves or makes')
                ->placeholder('—'),
            TextEntry::make('ingredients')
                ->state(fn (RecipeVersion $record): array => array_map(
                    fn (array $line): string => trim($line['quantity'].' '.$line['name'])
                        .(filled($line['note']) ? ', '.$line['note'] : '')
                        .($line['is_optional'] ? ' (optional)' : ''),
                    $record->previous['ingredients'],
                ))
                ->listWithLineBreaks()
                ->bulleted()
                ->placeholder('None'),
            TextEntry::make('substitutes')
                ->state(fn (RecipeVersion $record): array => array_map(
                    fn (array $substitute): string => 'Instead of '.$substitute['instead_of'].': '
                        .implode(' + ', array_map(fn (array $line): string => trim($line['quantity'].' '.$line['name']), $substitute['ingredients']))
                        .(filled($substitute['note']) ? ' ('.$substitute['note'].')' : ''),
                    $record->previous['substitutes'] ?? [],
                ))
                ->listWithLineBreaks()
                ->bulleted()
                ->hidden(fn (RecipeVersion $record): bool => blank($record->previous['substitutes'] ?? [])),
            TextEntry::make('variants')
                ->state(fn (RecipeVersion $record): array => array_map(
                    fn (array $variant): string => $variant['name'].': '
                        .implode(', ', array_map(fn (array $line): string => $line['is_left_out'] ? 'no '.$line['name'] : trim($line['quantity'].' '.$line['name']), $variant['ingredients']))
                        .(filled($variant['notes']) ? ' ('.$variant['notes'].')' : ''),
                    $record->previous['variants'] ?? [],
                ))
                ->listWithLineBreaks()
                ->bulleted()
                ->hidden(fn (RecipeVersion $record): bool => blank($record->previous['variants'] ?? [])),
            TextEntry::make('equipment')
                ->state(fn (RecipeVersion $record): array => $record->previous['equipment'])
                ->listWithLineBreaks()
                ->bulleted()
                ->placeholder('Nothing special'),
            TextEntry::make('steps')
                ->state(fn (RecipeVersion $record): array => collect($record->previous['steps'])
                    ->values()
                    ->map(fn (string $step, int $index): string => ($index + 1).'. '.$step)
                    ->all())
                ->listWithLineBreaks()
                ->placeholder('None'),
        ];
    }
}
