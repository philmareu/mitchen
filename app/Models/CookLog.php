<?php

namespace App\Models;

use Database\Factories\CookLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One time the cook made a recipe: when, which version (and variant), how it turned out and what to change
 * next time. Often filled in days later (pickles taste right on day three). The next-time
 * notes wait until a new version takes them in (`used_in_version`).
 */
#[Fillable(['cooked_on', 'version', 'variant', 'thoughts', 'next_time'])]
class CookLog extends Model
{
    /** @use HasFactory<CookLogFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (CookLog $log): void {
            $log->version ??= $log->recipe->version;
        });
    }

    protected function casts(): array
    {
        return [
            'cooked_on' => 'date',
            'version' => 'integer',
            'used_in_version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Recipe, $this>
     */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    /**
     * Next-time notes no version has taken in yet.
     */
    public function isWaiting(): bool
    {
        return filled($this->next_time) && $this->used_in_version === null;
    }

    /**
     * "Version 2, Hot".
     */
    public function madeAs(): string
    {
        return 'Version '.$this->version.(filled($this->variant) ? ', '.$this->variant : '');
    }

    /**
     * "Oct 12 · version 1: More salt."
     */
    public function summary(): string
    {
        return $this->cooked_on->format('M j').' · '.lcfirst($this->madeAs()).': '.$this->next_time;
    }
}
