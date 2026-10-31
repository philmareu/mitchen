<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * For a model whose `photos` (paths on the default disk) belong to it alone: the files are
 * deleted when they're taken off it or it's deleted, once the change is committed, so a
 * rolled-back save keeps its files.
 */
trait OwnsPhotos
{
    protected static function bootOwnsPhotos(): void
    {
        static::updated(function (self $model): void {
            if ($model->wasChanged('photos')) {
                self::deletePhotos(array_diff($model->getOriginal('photos') ?? [], $model->photos ?? []));
            }
        });

        static::deleted(fn (self $model) => self::deletePhotos($model->photos ?? []));
    }

    /**
     * @param  array<string>  $paths
     */
    private static function deletePhotos(array $paths): void
    {
        if ($paths !== []) {
            DB::afterCommit(fn () => Storage::delete(array_values($paths)));
        }
    }
}
