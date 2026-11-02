<?php

namespace App\Models;

use App\Models\Builders\RecipeEquipmentBuilder;
use Database\Factories\RecipeEquipmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A piece of equipment a recipe calls for. Whenever its name changes (or it isn't linked) it's
 * linked to the kitchen's equipment of that name, taking its spelling; when the kitchen doesn't
 * have one it stays unlinked until a piece of that name is added.
 */
#[Fillable(['name', 'sort'])]
#[UseEloquentBuilder(RecipeEquipmentBuilder::class)]
class RecipeEquipment extends Model
{
    /** @use HasFactory<RecipeEquipmentFactory> */
    use HasFactory;

    protected $table = 'recipe_equipment';

    protected static function booted(): void
    {
        static::saving(function (RecipeEquipment $piece): void {
            if ($piece->equipment_id !== null && ! $piece->isDirty('name')) {
                return;
            }

            $equipment = Equipment::query()->named($piece->name)->first();

            $piece->equipment()->associate($equipment);
            $piece->name = $equipment->name ?? trim($piece->name);
        });
    }

    /**
     * @return BelongsTo<Recipe, $this>
     */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    /**
     * @return BelongsTo<Equipment, $this>
     */
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function isInKitchen(): bool
    {
        return $this->equipment_id !== null;
    }
}
