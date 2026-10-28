<?php

namespace App\Listeners;

use App\Models\AiCall;
use App\Support\AiPricing;
use Illuminate\Support\Str;
use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Prompts\AgentPrompt;

/**
 * Keeps a record of every agent call (tokens, estimated cost, duration, failures)
 * for the AI usage page. The SDK reports usage but doesn't keep or price it.
 *
 * Bound as a singleton so a call's start time survives until it finishes.
 */
class RecordAiUsage
{
    /** @var array<string, int> hrtime() readings by invocation id. */
    private array $startedAt = [];

    public function handlePromptingAgent(PromptingAgent $event): void
    {
        $this->startedAt[$event->invocationId] = hrtime(true);
    }

    public function handleAgentPrompted(AgentPrompted $event): void
    {
        $usage = $event->response->usage;
        $model = $event->response->meta->model ?? $event->prompt->model;

        $this->record($event->invocationId, $event->prompt, [
            'provider' => $event->response->meta->provider ?? $event->prompt->provider()->name(),
            'model' => $model,
            'input_tokens' => $usage->inputTokens,
            'output_tokens' => $usage->outputTokens,
            'cache_read_tokens' => $usage->cacheReadInputTokens ?? 0,
            'cache_write_tokens' => $usage->cacheWriteInputTokens ?? 0,
            'cost' => AiPricing::cost($model, $usage),
        ]);
    }

    public function handleAgentFailed(AgentFailed $event): void
    {
        $this->record($event->invocationId, $event->prompt, [
            'provider' => $event->prompt->provider()->name(),
            'model' => $event->prompt->model,
            'cost' => 0,
            'error' => Str::limit($event->exception->getMessage() ?: $event->exception::class, 490),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function record(string $invocationId, AgentPrompt $prompt, array $attributes): void
    {
        $startedAt = $this->startedAt[$invocationId] ?? null;
        unset($this->startedAt[$invocationId]);

        // Bookkeeping must never break the feature that made the call.
        rescue(fn () => AiCall::create([
            'invocation_id' => $invocationId,
            'user_id' => auth()->id(),
            'agent' => $prompt->agent::class,
            'duration_ms' => $startedAt === null ? null : (int) round((hrtime(true) - $startedAt) / 1e6),
            ...$attributes,
        ]));
    }
}
