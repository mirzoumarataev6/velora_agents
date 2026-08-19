<?php

namespace Tests\Feature\Perf;

use App\Models\Agent;
use App\Models\KnowledgeSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Guards the dashboard pages against N+1 regressions: the query count must stay
 * flat as the number of agents / sources grows, not scale with it.
 */
class DashboardQueryCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_agents_index_query_count_is_flat_regardless_of_agent_count(): void
    {
        $user = User::factory()->create();
        Agent::factory()->count(8)->for($user)->create();

        $this->actingAs($user);

        DB::enableQueryLog();
        $response = $this->get('/agents');
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertOk();
        // Session + user + one agents query (with withCount subqueries folded in).
        // Comfortably under a per-agent explosion (which would be 8+).
        $this->assertLessThanOrEqual(12, $count, "Agents index ran {$count} queries — possible N+1.");
    }

    public function test_agent_detail_query_count_is_bounded(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();
        KnowledgeSource::factory()->count(5)->for($agent)->create();

        $this->actingAs($user);

        DB::enableQueryLog();
        $response = $this->get(route('agents.show', $agent));
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertOk();
        $this->assertLessThanOrEqual(15, $count, "Agent detail ran {$count} queries — possible N+1.");
    }
}
