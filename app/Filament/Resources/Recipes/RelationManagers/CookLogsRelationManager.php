<?php

namespace App\Filament\Resources\Recipes\RelationManagers;

use App\Filament\Resources\Recipes\Pages\ViewRecipe;
use App\Models\CookLog;
use App\Models\Recipe;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Livewire\Attributes\On;

/**
 * Each time the cook made the recipe, on the recipe page. Entries are added with "Made it"
 * in the page header and edited here, for thoughts that come days later.
 */
class CookLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'cookLogs';

    protected static ?string $title = 'Cook log';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $pageClass === ViewRecipe::class;
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    /**
     * Also used by "Made it".
     *
     * @return list<Component>
     */
    public static function fields(Recipe $recipe): array
    {
        // On the cook log it's the entry; for "Made it" the recipe.
        $madeAs = fn (mixed $record): ?string => $record instanceof CookLog ? $record->variant : null;

        return [
            DatePicker::make('cooked_on')
                ->label('Made on')
                ->required()
                ->maxDate(today())
                ->default(today()),
            Select::make('variant')
                ->options(fn (mixed $record): array => $recipe->variants
                    ->pluck('name')
                    ->when(filled($madeAs($record)), fn (Collection $names) => $names->push($madeAs($record)))
                    ->unique()
                    ->mapWithKeys(fn (string $name): array => [$name => $name])
                    ->all())
                ->placeholder('As written')
                ->visible(fn (mixed $record): bool => $recipe->variants->isNotEmpty() || filled($madeAs($record))),
            Textarea::make('thoughts')
                ->label('How it turned out')
                ->rows(2)
                ->maxLength(5_000)
                ->placeholder('Good, but a bit sharp. Better on day three.'),
            Textarea::make('next_time')
                ->label('Next time')
                ->rows(2)
                ->maxLength(5_000)
                ->placeholder('More salt, a little less vinegar.')
                ->helperText('Update from notes works these into a new version of the recipe.'),
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components(self::fields($this->recipe()))
            ->columns(1);
    }

    private function recipe(): Recipe
    {
        /** @var Recipe */
        return $this->getOwnerRecord();
    }

    /**
     * "Made it" in the page header adds entries.
     */
    #[On('cook-log-updated')]
    public function refreshCookLog(): void {}

    public function table(Table $table): Table
    {
        return $table
            ->recordTitle(fn (CookLog $record): string => 'the entry for '.$record->cooked_on->format('M j'))
            ->emptyStateIcon(Heroicon::OutlinedClipboardDocumentList)
            ->emptyStateHeading('Not made yet')
            ->emptyStateDescription('Press "Made it" after you cook this, and note what to change next time.')
            ->columns([
                TextColumn::make('cooked_on')
                    ->label('Made')
                    ->date('M j, Y')
                    ->description(fn (CookLog $record): string => $record->madeAs()),
                TextColumn::make('thoughts')
                    ->label('How it turned out')
                    ->wrap()
                    ->placeholder('—'),
                TextColumn::make('next_time')
                    ->label('Next time')
                    ->wrap()
                    ->placeholder('—')
                    ->description(fn (CookLog $record): ?string => match (true) {
                        $record->used_in_version !== null => 'Used in version '.$record->used_in_version,
                        $record->isWaiting() => 'Not in the recipe yet',
                        default => null,
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->modalHeading('Edit the cook log')
                    ->after(fn () => $this->dispatch('cook-log-updated')),
                DeleteAction::make()
                    ->after(fn () => $this->dispatch('cook-log-updated')),
            ]);
    }
}
