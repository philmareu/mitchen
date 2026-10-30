<?php

namespace App\Filament\Resources\Recipes\Pages;

use App\Actions\RecordRecipeVersion;
use App\Filament\Resources\Recipes\RecipeResource;
use App\Models\Recipe;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

/**
 * @property Recipe $record
 */
class EditRecipe extends EditRecord
{
    protected static string $resource = RecipeResource::class;

    /**
     * Recipe::snapshot() from before saving, for the new version to keep.
     *
     * @var array<string, mixed>
     */
    private array $before = [];

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make()
                ->modalDescription('Its photos are deleted too. The ingredients stay in your catalog.'),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return [...$data, 'new_version' => true];
    }

    protected function beforeSave(): void
    {
        $this->before = $this->record->snapshot();
    }

    /**
     * Unless the cook turned it off (fixing a typo), a change to the lines, steps or servings
     * makes a new version.
     */
    protected function afterSave(): void
    {
        if (! ($this->data['new_version'] ?? true)) {
            return;
        }

        app(RecordRecipeVersion::class)->handle(
            $this->record,
            $this->before,
            $this->data['version_note'] ?? null,
            array_map(intval(...), $this->data['used_notes'] ?? []),
        );
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResourceUrl('view');
    }
}
