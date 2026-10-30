<?php

namespace App\Models;

use App\Enums\EquipmentCategory;
use App\Models\Builders\EquipmentBuilder;
use Database\Factories\EquipmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * A piece of kitchen equipment, from a stand mixer down to a ladle. Its photos are kept
 * (on the default disk, privately) as a reference for the cook's own gear. Each piece
 * owns its photo files: they're deleted when it is, or when they're taken off it.
 */
#[Fillable(['name', 'category', 'brand', 'size', 'count', 'photos', 'notes'])]
#[UseEloquentBuilder(EquipmentBuilder::class)]
class Equipment extends Model
{
    /** @use HasFactory<EquipmentFactory> */
    use HasFactory;

    /**
     * Where photos are stored on the disk.
     */
    public const string PHOTO_DIRECTORY = 'equipment';

    protected $attributes = [
        'count' => 1,
    ];

    protected static function booted(): void
    {
        static::updated(function (Equipment $equipment): void {
            if ($equipment->wasChanged('photos')) {
                self::deletePhotos(array_diff($equipment->getOriginal('photos') ?? [], $equipment->photos ?? []));
            }
        });

        static::deleted(fn (Equipment $equipment) => self::deletePhotos($equipment->photos ?? []));
    }

    protected function casts(): array
    {
        return [
            'category' => EquipmentCategory::class,
            'count' => 'integer',
            'photos' => 'array',
        ];
    }

    /**
     * "KitchenAid Artisan · 5 qt", or null when there's nothing to add to the name.
     */
    public function details(): ?string
    {
        return collect([$this->brand, $this->size])->filter()->join(' · ') ?: null;
    }

    /**
     * Once the change is committed, so a rolled-back save keeps its files.
     *
     * @param  array<string>  $paths
     */
    private static function deletePhotos(array $paths): void
    {
        if ($paths !== []) {
            DB::afterCommit(fn () => Storage::delete(array_values($paths)));
        }
    }
}
