<?php

namespace App\Actions;

use App\Enums\EquipmentChangeKind;
use App\Models\Equipment;
use App\Support\ProposedEquipment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Saves the equipment the cook confirmed from a note or photos, all or nothing. Each piece
 * gets its own copy of the photos it's in, so it can be cropped or deleted on its own.
 */
class ApplyEquipmentUpdate
{
    /**
     * @param  list<ProposedEquipment>  $pieces
     * @param  list<UploadedFile>  $photos  The attached photos, in the order the pieces count them.
     * @return list<string> What happened, one line per piece.
     */
    public function handle(array $pieces, array $photos = []): array
    {
        $copies = array_map(fn (ProposedEquipment $piece): array => $this->storePhotos($piece, $photos), $pieces);

        try {
            return DB::transaction(fn (): array => array_map($this->apply(...), $pieces, $copies));
        } catch (Throwable $exception) {
            Storage::delete(Arr::flatten($copies));

            throw $exception;
        }
    }

    /**
     * @param  list<string>  $photos  Stored copies of its photos.
     */
    private function apply(ProposedEquipment $piece, array $photos): string
    {
        $equipment = $this->find($piece);

        if ($piece->kind === EquipmentChangeKind::Gone) {
            if ($equipment === null) {
                return "{$piece->name}: not listed, nothing removed";
            }

            $equipment->delete();

            return "Removed {$equipment->name}";
        }

        $details = [
            'name' => $piece->name,
            'category' => $piece->category,
            'brand' => $piece->brand,
            'size' => $piece->size,
            'count' => $piece->count,
        ];

        $withPhotos = $photos === [] ? '' : count($photos).' '.Str::plural('photo', count($photos));

        if ($equipment === null) {
            Equipment::create([...$details, 'photos' => $photos]);

            return "Added {$piece->name}".($withPhotos === '' ? '' : " with {$withPhotos}");
        }

        $equipment->update([...$details, 'photos' => [...$equipment->photos ?? [], ...$photos]]);

        return "Updated {$equipment->name}".($withPhotos === '' ? '' : ", added {$withPhotos}");
    }

    /**
     * The listed piece it's about: by id, or by name when the cook changed a new piece to one
     * they already have.
     */
    private function find(ProposedEquipment $piece): ?Equipment
    {
        if ($piece->kind === EquipmentChangeKind::Added) {
            return null;
        }

        return $piece->equipmentId !== null
            ? Equipment::find($piece->equipmentId)
            : Equipment::query()->named($piece->name)->first();
    }

    /**
     * @param  list<UploadedFile>  $photos
     * @return list<string> Paths of the stored copies.
     */
    private function storePhotos(ProposedEquipment $piece, array $photos): array
    {
        if ($piece->kind === EquipmentChangeKind::Gone) {
            return [];
        }

        return collect($piece->photos)
            ->map(fn (int $index): ?UploadedFile => $photos[$index] ?? null)
            ->filter()
            // A fresh name each time: hashName() is cached per file, and a photo can be in several pieces.
            ->map(fn (UploadedFile $photo): string|false => $photo->storeAs(Equipment::PHOTO_DIRECTORY, Str::random(40).'.'.($photo->extension() ?: 'jpg')))
            ->filter()
            ->values()
            ->all();
    }
}
