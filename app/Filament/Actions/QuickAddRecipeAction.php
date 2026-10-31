<?php

namespace App\Filament\Actions;

use App\Actions\AddRecipe;
use App\Actions\ReadRecipe;
use App\Ai\Agents\RecipeReader;
use App\Filament\Resources\Equipment\EquipmentResource;
use App\Filament\Resources\Recipes\RecipeResource;
use App\Support\RecipeDraft;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Image;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Enums\Width;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

/**
 * Photos of a recipe (a handwritten card, a cookbook page, the back of a package) and/or a
 * note: the assistant reads the recipe, the cook checks it, and it's saved with its photos.
 */
class QuickAddRecipeAction extends Action
{
    private const int MAX_PHOTOS = 4;

    public static function getDefaultName(): ?string
    {
        return 'quickAddRecipe';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Quick add')
            ->icon(Heroicon::OutlinedSparkles)
            ->color('gray')
            ->disabled(fn (): bool => ! RecipeReader::isConfigured())
            ->tooltip(fn (): ?string => RecipeReader::isConfigured() ? null : 'Add ANTHROPIC_API_KEY to .env to use the assistant')
            ->modalHeading('Quick add a recipe')
            ->modalDescription('Add photos of a recipe card, a cookbook page or the back of a package, and/or type or paste the recipe. You\'ll check everything before it\'s saved.')
            ->modalWidth(Width::SevenExtraLarge)
            ->modalSubmitActionLabel('Save recipe')
            ->steps([
                Step::make('photos')
                    ->label('What\'s the recipe?')
                    ->schema([
                        EquipmentResource::photoUpload('photos')
                            ->maxFiles(self::MAX_PHOTOS)
                            ->panelLayout('grid')
                            ->helperText('Front and back of a card count as two photos. They\'re kept with the recipe. Reading handwriting can take up to a minute.')
                            ->storeFiles(false),
                        Textarea::make('note')
                            ->requiredWithout('photos')
                            ->rows(4)
                            ->maxLength(10_000)
                            ->placeholder('Grandma\'s chili, serves 6. I use pinto beans instead of kidney beans. Or paste a whole recipe here.'),
                        Hidden::make('not_understood'),
                    ])
                    ->afterValidation(function (Get $get, Set $set, ReadRecipe $read): void {
                        $photos = array_map(fn (UploadedFile $file): string => (string) $file->getRealPath(), $this->uploadedPhotos($get('photos')));

                        $this->read((string) $get('note'), $photos, $set, $read);
                    }),
                Step::make('review')
                    ->label('Check the recipe')
                    ->schema([
                        Callout::make('Couldn\'t read everything')
                            ->description(fn (Get $get): string => collect(Arr::wrap($get('not_understood')))->map(fn (mixed $text): string => '“'.$text.'”')->join(', '))
                            ->warning()
                            ->visible(fn (Get $get): bool => filled($get('not_understood'))),
                        $this->photoPreviews(),
                        ...RecipeResource::draftFields(),
                    ]),
            ])
            ->action(function (array $data, AddRecipe $add): void {
                $recipe = $add->handle(RecipeDraft::fromArray($data), $this->uploadedPhotos($data['photos'] ?? []));

                Notification::make()
                    ->title('Recipe saved')
                    ->body($recipe->name)
                    ->success()
                    ->send();

                $this->redirect(RecipeResource::getUrl('view', ['record' => $recipe]));
            });
    }

    /**
     * @param  list<string>  $photos
     */
    private function read(string $note, array $photos, Set $set, ReadRecipe $read): void
    {
        try {
            $draft = $read->handle($note, $photos);
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()
                ->title('The assistant couldn\'t read that right now')
                ->body('Check that ANTHROPIC_API_KEY is set and try again in a moment. The usage page has the error.')
                ->danger()
                ->send();

            throw new Halt;
        }

        if ($draft->isEmpty()) {
            Notification::make()
                ->title('No recipe found')
                ->body($photos === []
                    ? 'That note doesn\'t look like a recipe. Paste the ingredients and steps, or add a photo of the card.'
                    : 'No recipe found in those photos. Try a sharper photo with the whole card in view.')
                ->warning()
                ->send();

            throw new Halt;
        }

        RecipeResource::fillDraft($set, $draft);
    }

    /**
     * The attached photos, to check the recipe against.
     */
    private function photoPreviews(): Grid
    {
        return Grid::make(self::MAX_PHOTOS)
            ->schema(array_map(fn (int $index): Group => Group::make([
                Text::make('Photo '.($index + 1)),
                Image::make(
                    url: fn (Get $get): string => $this->previewUrl($this->uploadedPhotos($get('photos'))[$index] ?? null),
                    alt: 'Photo '.($index + 1),
                )->imageHeight('12rem'),
            ])->visible(fn (Get $get): bool => isset($this->uploadedPhotos($get('photos'))[$index])), range(0, self::MAX_PHOTOS - 1)))
            ->visible(fn (Get $get): bool => $this->uploadedPhotos($get('photos')) !== []);
    }

    /**
     * @return list<UploadedFile>
     */
    private function uploadedPhotos(mixed $state): array
    {
        return collect(Arr::wrap($state))
            ->filter(fn (mixed $file): bool => $file instanceof UploadedFile)
            ->values()
            ->all();
    }

    private function previewUrl(?UploadedFile $photo): string
    {
        return $photo instanceof TemporaryUploadedFile && $photo->isPreviewable() ? $photo->temporaryUrl() : '';
    }
}
