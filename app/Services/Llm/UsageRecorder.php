<?php

namespace App\Services\Llm;

use App\Models\Agent;
use App\Models\UsageLog;
use Illuminate\Support\Facades\Log;
use Throwable;

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

            Log::warning('Failed to persist usage log', [
                'provider' => $provider,
                'operation' => $operation,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
