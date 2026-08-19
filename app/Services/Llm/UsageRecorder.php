<?php

namespace App\Services\Llm;

use App\Models\Agent;
use App\Models\UsageLog;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Records provider token usage into the usage_logs table.
 *
 * Providers are deliberately unaware of agents and users, so the owning
 * context is set here by whoever *does* know it (a job, a controller, a
 * service) and read back by the provider when it logs. Registered as a
 * scoped singleton: one context per request/job, reset between them.
 *
 * With no agent bound, usage is still written to the log channel but no
 * usage_logs row is created — the table requires a user_id, and inventing
 * one would be worse than the gap.
 */
class UsageRecorder
{
    public const TYPE_EMBEDDING = 'embedding';

    public const TYPE_GENERATION = 'generation';

    protected ?Agent $agent = null;

    public function for(?Agent $agent): static
    {
        $this->agent = $agent;

        return $this;
    }

    public function currentAgent(): ?Agent
    {
        return $this->agent;
    }

    /**
     * Run a callback with the given agent bound as the usage context, then
     * restore whatever was bound before. Use this when the previous context
     * still matters after the callback returns.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function scoped(?Agent $agent, callable $callback): mixed
    {
        $previous = $this->agent;
        $this->agent = $agent;

        try {
            return $callback();
        } finally {
            $this->agent = $previous;
        }
    }

    /**
     * @param  self::TYPE_*  $type
     */
    public function record(string $provider, string $operation, string $type, int $inputTokens, int $outputTokens): void
    {
        Log::info('llm.usage', [
            'provider' => $provider,
            'operation' => $operation,
            'type' => $type,
            'agent_id' => $this->agent?->id,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
        ]);

        if (! $this->agent) {
            return;
        }

        try {
            $log = new UsageLog([
                'type' => $type,
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
            ]);
            $log->user_id = $this->agent->user_id;
            $log->agent_id = $this->agent->id;
            $log->save();
        } catch (Throwable $e) {
            // Usage accounting must never take down a customer reply.
            Log::warning('Failed to persist usage log', [
                'provider' => $provider,
                'operation' => $operation,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
