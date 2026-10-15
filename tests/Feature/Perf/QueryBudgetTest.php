<?php

namespace Tests\Feature\Perf;

use App\Models\Agent;
use App\Models\Channel;
use App\Models\Customer;
use App\Models\Deal;
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

    /**
     * Settings renders one Telegram lead-alert card per agent, and each card
     * asks whether that agent's Telegram channel is connected. That read used
     * to lazy-load `$agent->channels` per card — one query per agent, so the
     * page got slower every time the owner added an agent (measured on a
     * seeded database: 5 agents, 5 queries, 7 total).
     *
     * Every agent here gets a channel, so a reintroduced lazy load costs one
     * query per agent and walks straight through this budget.
     */
    public function test_settings_does_not_query_channels_once_per_agent(): void
    {
        $user = User::factory()->create();

        foreach (Agent::factory()->for($user)->count(9)->create() as $agent) {
            Channel::factory()->for($agent)->create();
        }

        $this->actingAs($user);

        $settings = $this->profile(route('settings.index'));
        $this->assertSame(200, $settings['status']);
        // Eager-loaded this is 3 queries and stays 3 for ninety agents; the
        // lazy load it replaced cost 11 here. The budget sits between the two
        // ON PURPOSE and with little headroom — a generous one (12, the first
        // number tried) let the 11-query regression pass and proved nothing.
        $this->assertLessThanOrEqual(6, $settings['count'], 'settings looks like it queries channels per agent');
    }

    /**
     * The CRM board is the query-heaviest page in the product that is not the
     * dashboard: it runs a set of one-off aggregates over deals and customers
     * (stage totals, lost reasons, origin split, a customers-without-deals
     * count) on top of the board itself.
     *
     * It was already free of N+1s when this budget was written — the deal,
     * customer, conversation and channel loads are all batched `in (...)`
     * queries, and the only repeated statement is `crm_stages`, loaded twice
     * by two different consumers. The budget exists to keep it that way: the
     * aggregates are a fixed cost, so any growth here is a per-row lookup.
     */
    public function test_crm_board_stays_within_a_flat_query_budget(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();

        // Enough rows across enough customers that a per-deal or per-customer
        // lookup cannot hide inside the budget.
        $customers = Customer::factory()->for($user)->count(12)->create([
            'team_id' => $user->current_team_id,
        ]);

        foreach ($customers as $customer) {
            Deal::factory()->for($user)->create([
                'customer_id' => $customer->id,
                'agent_id' => $agent->id,
                'team_id' => $user->current_team_id,
            ]);
        }

        $this->actingAs($user);

        $board = $this->profile(route('crm.board'));
        $this->assertSame(200, $board['status']);
        // 22 as measured, so the headroom is smaller than the 12 rows above:
        // a per-deal or per-customer lookup adds 12 and fails this.
        $this->assertLessThanOrEqual(26, $board['count'], 'crm.board looks like it has an N+1');
    }
}
