<?php

use App\Actions\ReadKitchenUpdate;
use App\Ai\Agents\KitchenUpdateReader;
use App\Filament\Resources\AiCalls\Pages\ManageAiCalls;
use App\Filament\Resources\AiCalls\Widgets\AiUsageOverview;
use App\Models\AiCall;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\StructuredTextResponse;

use function Pest\Livewire\livewire;

function answerWithUsage(TextUsage $usage, string $model = 'claude-haiku-5-5'): StructuredTextResponse
{
    $answer = ['changes' => [], 'not_understood' => []];

    return new StructuredTextResponse($answer, json_encode($answer), $usage, new Meta('anthropic', $model));
}

it('records each call with its tokens, estimated cost and duration', function () {
    $cook = actingAsCook();
    KitchenUpdateReader::fake([answerWithUsage(new TextUsage(12_000, 800, cacheReadInputTokens: 2_000))]);

    app(ReadKitchenUpdate::class)->handle('out of milk');

    expect(AiCall::sole())
        ->agent->toBe(KitchenUpdateReader::class)
        ->user_id->toBe($cook->id)
        ->provider->toBe('anthropic')
        ->model->toBe('claude-haiku-5-5')
        ->input_tokens->toBe(12_000)
        ->output_tokens->toBe(800)
        ->cache_read_tokens->toBe(2_000)
        ->cost->toEqualWithDelta((10_000 * 0.10 + 2_000 * 0.01 + 800 * 0.50) / 1_000_000, 1e-9)
        ->duration_ms->toBeGreaterThanOrEqual(0)
        ->error->toBeNull()
        ->and(AiCall::sole()->feature())->toBe('Kitchen update reader');
});

it('records failed calls', function () {
    KitchenUpdateReader::fake(fn () => throw new RuntimeException('Overloaded'));

    expect(fn () => app(ReadKitchenUpdate::class)->handle('out of milk'))->toThrow(RuntimeException::class);

    expect(AiCall::sole())
        ->error->toBe('Overloaded')
        ->cost->toEqual(0.0)
        ->user_id->toBeNull()
        ->and(AiCall::sole()->failed())->toBeTrue();
});

it('never lets bookkeeping break the feature', function () {
    Schema::drop('ai_calls');
    KitchenUpdateReader::fake([answerWithUsage(new TextUsage(100, 10))]);

    expect(app(ReadKitchenUpdate::class)->handle('out of milk')->changes)->toBe([]);
});

it('shows the usage log and what it cost', function () {
    actingAsCook();
    $recent = AiCall::factory()->create(['cost' => 0.0012]);
    $failed = AiCall::factory()->failed('Connection refused')->create();
    $old = AiCall::factory()->create(['cost' => 0.25, 'created_at' => now()->subMonths(2)]);

    $this->get('/ai-usage')->assertOk();

    livewire(ManageAiCalls::class)
        ->assertCanSeeTableRecords([$recent, $failed, $old])
        ->assertSee('Kitchen update reader')
        ->assertSee('$0.0012')
        ->filterTable('failed')
        ->assertCanSeeTableRecords([$failed])
        ->assertCanNotSeeTableRecords([$recent, $old])
        ->resetTableFilters()
        ->filterTable('period', 'this_month')
        ->assertCanNotSeeTableRecords([$old]);

    livewire(AiUsageOverview::class)
        ->assertSee('$0.0012')
        ->assertSee('$0.2512')
        ->assertSee('Failed calls');
});
