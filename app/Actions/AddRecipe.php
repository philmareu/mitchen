<?php

namespace App\Actions;

use App\Models\Ingredient;
use App\Models\Recipe;
use App\Support\RecipeDraft;
use App\Support\RecipeLine;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Saves a recipe the cook checked, with its photos, all or nothing. Ingredients the catalog
 * doesn't have yet are added to it as one-offs in the aisle the assistant suggested.
 */
class AddRecipe
{
    /**
     * @param  list<UploadedFile>  $photos  The original card or page.
     */
    public function handle(RecipeDraft $draft, array $photos = []): Recipe
    {
        $paths = collect($photos)
            ->map(fn (UploadedFile $photo): string|false => $photo->storeAs(Recipe::PHOTO_DIRECTORY, Str::random(40).'.'.($photo->extension() ?: 'jpg')))
            ->filter()
            ->values()
            ->all();

        try {
            return DB::transaction(fn (): Recipe => $this->save($draft, $paths));
        } catch (Throwable $exception) {
            Storage::delete($paths);

            throw $exception;
        }
    }

    /**
     * @param  list<string>  $photos
     */
    private function save(RecipeDraft $draft, array $photos): Recipe
    {
        $recipe = Recipe::create([
            'name' => $draft->name,
            'source' => $draft->source,
            'servings' => $draft->servings,
            'steps' => $draft->steps,
            'notes' => $draft->notes,
            'photos' => $photos,
        ]);

        foreach ($draft->ingredients as $sort => $line) {
            $this->addToCatalog($line);

            $recipe->ingredients()->create([
                'name' => $line->name,
                'quantity' => $line->quantity,
                'note' => $line->note,
                'is_optional' => $line->isOptional,
                'sort' => $sort,
            ]);
        }

        foreach ($draft->equipment as $sort => $name) {
            $recipe->equipment()->create(['name' => $name, 'sort' => $sort]);
        }

        return $recipe;
    }

    /**
     * Saving the line would add a new ingredient anyway, but without an aisle.
     */
    private function addToCatalog(RecipeLine $line): void
    {
        if ($line->category !== null && ! Ingredient::query()->named($line->name)->exists()) {
            Ingredient::create([
                'name' => Str::ucfirst($line->name),
                'category' => $line->category,
                'is_core' => false,
            ]);
        }
    }
}
