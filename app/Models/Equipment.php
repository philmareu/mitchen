<?php

namespace App\Models;

use App\Enums\EquipmentCategory;
use App\Models\Builders\EquipmentBuilder;
use App\Models\Concerns\OwnsPhotos;
use Database\Factories\EquipmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A piece of kitchen equipment, from a stand mixer down to a ladle. Its photos are kept
 * (on the default disk, privately) as a reference for the cook's own gear. Each piece
 * owns its photo files: they're deleted when it is, or when they're taken off it.
 *
 * Recipes that call for equipment of its name, which the kitchen didn't have, link to it
 * when it's added (or renamed to that name).
 */
#[Fillable(['name', 'category', 'brand', 'size', 'count', 'photos', 'notes'])]
#[UseEloquentBuilder(EquipmentBuilder::class)]
class Equipment extends Model
{
    /** @use HasFactory<EquipmentFactory> */
    use HasFactory;

    use OwnsPhotos;

    /**
     * Where photos are stored on the disk.
     */
    public const string PHOTO_DIRECTORY = 'equipment';

    protected $attributes = [
        'count' => 1,
    ];

    protected static function booted(): void
    {
        static::saved(function (Equipment $equipment): void {
            if ($equipment->wasRecentlyCreated || $equipment->wasChanged('name')) {
                RecipeEquipment::query()
                    ->whereNull('equipment_id')
                    ->whereRaw('lower(name) = ?', [mb_strtolower(trim($equipment->name))])
                    ->update(['equipment_id' => $equipment->id, 'name' => $equipment->name]);
            }
        });
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
}
