<?php

namespace App\Support;

use Laravel\Ai\Responses\Data\TextUsage;

/**
 * Estimates what a call cost from its token usage and the rates in config('ai.pricing').
 */
class AiPricing
{
    /**
     * The cost in US dollars, or null when the model has no price on file.
     */
    public static function cost(?string $model, TextUsage $usage): ?float
    {
        $rates = config("ai.pricing.{$model}");

        if (! is_array($rates)) {
            return null;
        }

        $longContext = $rates['long_context'] ?? null;

        if (is_array($longContext) && $usage->inputTokens > $longContext['above']) {
            $rates = $longContext;
        }

        $cacheRead = $usage->cacheReadInputTokens ?? 0;
        $cacheWrite = $usage->cacheWriteInputTokens ?? 0;

        return (
            $usage->uncachedInputTokens() * $rates['input']
            + $cacheWrite * $rates['cache_write']
            + $cacheRead * $rates['cache_read']
            + $usage->outputTokens * $rates['output']
        ) / 1_000_000;
    }

    /**
     * Dollars for reading: AI calls cost fractions of a cent, so small amounts keep four decimals.
     */
    public static function format(?float $dollars): string
    {
        if ($dollars === null) {
            return 'No price';
        }

        if ($dollars > 0 && $dollars < 0.0001) {
            return '<$0.0001';
        }

        return '$'.number_format($dollars, $dollars < 1 ? 4 : 2);
    }
}
