<?php

namespace App\Models;

use Database\Factories\AiCallFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One request to an AI model: who asked, which model answered, the tokens it used and what it cost.
 * Written by App\Listeners\RecordAiUsage from the AI SDK's events.
 */
#[Fillable([
    'invocation_id', 'user_id', 'agent', 'provider', 'model',
    'input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_write_tokens',
    'cost', 'duration_ms', 'error',
])]
class AiCall extends Model
{
    /** @use HasFactory<AiCallFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cache_read_tokens' => 'integer',
            'cache_write_tokens' => 'integer',
            'cost' => 'float',
            'duration_ms' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The feature that made the call, named after its agent: "Kitchen update reader".
     */
    public function feature(): string
    {
        return Str::ucfirst(Str::lower(Str::headline(class_basename($this->agent))));
    }

    public function failed(): bool
    {
        return $this->error !== null;
    }
}
