<?php

use App\Support\AiPricing;
use Laravel\Ai\Responses\Data\TextUsage;
use Tests\TestCase;

uses(TestCase::class);

it('prices input, output and cache tokens at the model’s rates', function () {
    // 10,000 uncached + 2,000 cache reads + 500 cache writes in; 800 out.
    $usage = new TextUsage(12_500, 800, cacheReadInputTokens: 2_000, cacheWriteInputTokens: 500);

    expect(AiPricing::cost('claude-haiku-5-5', $usage))
        ->toEqualWithDelta((10_000 * 0.10 + 500 * 0.125 + 2_000 * 0.01 + 800 * 0.50) / 1_000_000, 1e-12)
        ->and(AiPricing::cost('claude-opus-5-5', $usage))
        ->toEqualWithDelta((10_000 * 4 + 500 * 5 + 2_000 * 0.20 + 800 * 20) / 1_000_000, 1e-12);
});

it('switches to long-context rates above the threshold', function () {
    $usage = new TextUsage(150_000, 1_000);

    expect(AiPricing::cost('claude-haiku-5-5', $usage))
        ->toEqualWithDelta((150_000 * 0.50 + 1_000 * 2.50) / 1_000_000, 1e-12);
});

it('has no price for models it does not know', function () {
    expect(AiPricing::cost('some-local-model', new TextUsage(1_000, 100)))->toBeNull()
        ->and(AiPricing::cost(null, new TextUsage(1_000, 100)))->toBeNull();
});

it('formats fractions of a cent readably', function (?float $dollars, string $expected) {
    expect(AiPricing::format($dollars))->toBe($expected);
})->with([
    [null, 'No price'],
    [0.0, '$0.0000'],
    [0.00004, '<$0.0001'],
    [0.00142, '$0.0014'],
    [0.0347, '$0.0347'],
    [12.5, '$12.50'],
]);
