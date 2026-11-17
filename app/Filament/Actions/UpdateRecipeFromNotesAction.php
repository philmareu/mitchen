<?php

namespace App\Filament\Actions;

use App\Actions\ReviseRecipe;
use App\Actions\SuggestCatalogNames;
use App\Actions\UpdateRecipe;
use App\Ai\Agents\RecipeReviser;
use App\Filament\Resources\Recipes\RecipeResource;
use App\Models\CookLog;
use App\Models\Recipe;
use App\Support\RecipeDraft;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Enums\Width;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;
use Throwable;

/**
 * On a recipe page: the assistant works the cook's notes for next time (and anything else they
 * type) into the recipe, the cook checks it, and it's saved as a new version.
 */
class UpdateRecipeFromNotesAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'updateFromNotes';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Update from notes')
            ->icon(Heroicon::OutlinedSparkles)
            ->color('gray')
            ->badge(fn (Recipe $record): ?int => $record->waitingNotes()->count() ?: null)
            ->disabled(fn (): bool => ! RecipeReviser::isConfigured())
            ->tooltip(fn (): ?string => RecipeReviser::isConfigured() ? null : 'Add ANTHROPIC_API_KEY to .env to use the assistant')
            ->modalHeading('Update the recipe from your notes')
            ->modalDescription('The assistant works your notes into the recipe. You check it before it\'s saved as a new version, and this version stays in the history.')
            ->modalWidth(Width::SevenExtraLarge)
            ->modalSubmitActionLabel('Save as a new version')
            ->fillForm(fn (Recipe $record): array => [
                'cook_logs' => $record->waitingNotes()->map(fn (CookLog $log): string => (string) $log->id)->all(),
            ])
            ->steps([
                Step::make('notes')
                    ->label('What to change')
                    ->schema([
                        CheckboxList::make('cook_logs')
                            ->label('Your notes for next time')
                            ->options(fn (Recipe $record): array => $record->waitingNotes()
                                ->mapWithKeys(fn (CookLog $log): array => [(string) $log->id => $log->next_time])
                                ->all())
                            ->descriptions(fn (Recipe $record): array => $record->waitingNotes()
                                ->mapWithKeys(fn (CookLog $log): array => [(string) $log->id => collect([
                                    'Made '.$log->cooked_on->format('M j').', version '.$log->version,
                                    $log->thoughts,
                                ])->filter()->join(' · ')])
                                ->all())
                            ->visible(fn (Recipe $record): bool => $record->waitingNotes()->isNotEmpty()),
                        Textarea::make('instructions')
                            ->label(fn (Recipe $record): string => $record->waitingNotes()->isNotEmpty() ? 'Anything else to change?' : 'What should change?')
                            ->requiredWithout('cook_logs')
                            ->validationMessages(['required_without' => 'Tick a note or say what should change.'])
                            ->rows(3)
                            ->maxLength(5_000)
                            ->placeholder('Make it for 8. Use red wine vinegar.'),
                        Hidden::make('not_understood'),
                    ])
                    ->afterValidation(function (Get $get, Set $set, Recipe $record, ReviseRecipe $revise): void {
                        $this->revise($record, array_map(intval(...), Arr::wrap($get('cook_logs'))), (string) $get('instructions'), $set, $revise);
                    }),
                Step::make('review')
                    ->label('Check the new version')
                    ->schema([
                        Callout::make('Couldn\'t work in everything')
                            ->description(fn (Get $get): string => collect(Arr::wrap($get('not_understood')))->map(fn (mixed $text): string => '“'.$text.'”')->join(', '))
                            ->warning()
                            ->visible(fn (Get $get): bool => filled($get('not_understood'))),
                        Textarea::make('version_note')
                            ->label('What changed and why')
                            ->helperText('Shown in the recipe\'s history.')
                            ->rows(2)
                            ->maxLength(5_000),
                        ...RecipeResource::draftFields(),
                    ]),
            ])
            ->action(function (array $data, Recipe $record, UpdateRecipe $update): void {
                $version = $update->handle(
                    $record,
                    RecipeDraft::fromArray($data),
                    $data['version_note'] ?? null,
                    array_map(intval(...), Arr::wrap($data['cook_logs'] ?? [])),
                );

                Notification::make()
                    ->title($version === null ? 'Saved. Nothing in the recipe changed' : 'Saved as version '.$version->number)
                    ->success()
                    ->send();

                $this->redirect(RecipeResource::getUrl('view', ['record' => $record]));
            });
    }

    /**
     * @param  list<int>  $cookLogIds
     */
    private function revise(Recipe $recipe, array $cookLogIds, string $instructions, Set $set, ReviseRecipe $revise): void
    {
        try {
            $draft = $revise->handle($recipe, $cookLogIds, $instructions);
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()
                ->title('The assistant couldn\'t update it right now')
                ->body('Check that ANTHROPIC_API_KEY is set and try again in a moment. The usage page has the error.')
                ->danger()
                ->send();

            throw new Halt;
        }

        if ($draft->isEmpty()) {
            Notification::make()
                ->title('The assistant didn\'t send a recipe back')
                ->body('Try again, or edit the recipe by hand.')
                ->warning()
                ->send();

            throw new Halt;
        }

        RecipeResource::fillDraft($set, $draft, app(SuggestCatalogNames::class)->handle($draft->ingredientNames()));
    }
}
