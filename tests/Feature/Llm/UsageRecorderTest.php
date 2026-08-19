<?php

namespace Tests\Feature\Llm;

use App\Models\Agent;
use App\Models\UsageLog;
use App\Services\Llm\UsageRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsageRecorderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_writes_a_usage_log_row_scoped_to_the_bound_agent(): void
    {
        $agent = Agent::factory()->create();

        (new UsageRecorder)
            ->for($agent)
            ->record('openai', 'chat.completions', UsageRecorder::TYPE_GENERATION, 120, 45);

        $this->assertDatabaseCount('usage_logs', 1);

        $log = UsageLog::first();
        $this->assertSame($agent->id, $log->agent_id);
        $this->assertSame($agent->user_id, $log->user_id);
        $this->assertSame('generation', $log->type);
        $this->assertSame(120, $log->input_tokens);
        $this->assertSame(45, $log->output_tokens);
    }

    public function test_it_does_not_write_a_row_without_a_bound_agent(): void
    {
        (new UsageRecorder)
            ->record('openai', 'embeddings', UsageRecorder::TYPE_EMBEDDING, 30, 0);

        $this->assertDatabaseCount('usage_logs', 0);
    }

    public function test_scoped_restores_the_previous_agent(): void
    {
        $outer = Agent::factory()->create();
        $inner = Agent::factory()->create();

        $recorder = (new UsageRecorder)->for($outer);

        $recorder->scoped($inner, function () use ($recorder, $inner) {
            $this->assertSame($inner->id, $recorder->currentAgent()->id);
        });

        $this->assertSame($outer->id, $recorder->currentAgent()->id);
    }
}
