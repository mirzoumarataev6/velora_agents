<?php

namespace Tests\Feature\Perf;

use App\Models\Agent;
use App\Models\Channel;
use App\Models\KnowledgeSource;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Guards the data-heavy dashboard pages against N+1 regressions: each page must
 * run a bounded number of queries that does NOT grow with the number of agents,
 * leads, sources, or channels on screen. If someone drops an eager-load, the
 * per-row query these budgets exclude reappears and the assertion fails.
 *
 * The printed counts also double as the "how many queries per page" measurement.
 */
class QueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{count: int, status: int}
     */
    protected function profile(string $uri): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->get($uri);

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        fwrite(STDERR, sprintf("[query-budget] %-40s status=%d queries=%d\n", $uri, $response->getStatusCode(), $count));

        return ['count' => $count, 'status' => $response->status()];
    }

    public function test_data_pages_stay_within_a_flat_query_budget(): void
    {
        $user = User::factory()->create();

        // A deliberately busy account: many agents, and one agent loaded up with
        // sources, channels, and leads. If any list re-queries per row, these
        // counts blow past the budgets below.
        $agent = Agent::factory()->for($user)->create();
        Agent::factory()->for($user)->count(6)->create();
        KnowledgeSource::factory()->for($agent)->count(5)->create();
        Channel::factory()->for($agent)->create();
        Channel::factory()->for($agent)->whatsapp()->create();
        Lead::factory()->for($agent)->count(25)->create();

        $this->actingAs($user);

        // Budgets are generous (session, auth, CSRF, the query itself, counts),
        // but flat: they hold whether the account has 7 agents or 700.
        $agents = $this->profile(route('agents.index'));
        $this->assertSame(200, $agents['status']);
        $this->assertLessThanOrEqual(12, $agents['count'], 'agents.index looks like it has an N+1');

        $show = $this->profile(route('agents.show', $agent));
        $this->assertSame(200, $show['status']);
        $this->assertLessThanOrEqual(20, $show['count'], 'agents.show looks like it has an N+1');

        $leads = $this->profile(route('leads.index'));
        $this->assertSame(200, $leads['status']);
        $this->assertLessThanOrEqual(14, $leads['count'], 'leads.index looks like it has an N+1');

        $poll = $this->profile(route('leads.poll'));
        $this->assertSame(200, $poll['status']);
        $this->assertLessThanOrEqual(8, $poll['count'], 'leads.poll looks like it has an N+1');
    }
}
