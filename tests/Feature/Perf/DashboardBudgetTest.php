<?php

namespace Tests\Feature\Perf;

use App\Models\Agent;
use App\Models\Channel;
use App\Models\Lead;
use App\Models\User;
use App\Services\Haggling\HagglingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * /dashboard is the first page of every session and, measured on a realistic
 * seed (12 agents, 3k conversations, 42k messages, 453 leads, on a scratch
 * MySQL 8.4 database — never the real one), the slowest: 266ms and 28
 * queries before the forRange() de-duplication and the messages(role,
 * created_at) index landed, ~240ms and 19 queries after.
 *
 * This budget is set from THIS suite's own smaller fixture (sqlite, not the
 * MySQL seed above — query COUNT doesn't depend on row volume or engine,
 * only on which queries run), so the number below is this test's own
 * measurement, not the MySQL one. It was verified to fail against the
 * pre-fix code (commit 27cb6af, the tip this perf work started from) before
 * being set here — see the perf task's own report for that run.
 */
class DashboardBudgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_stays_within_a_flat_query_budget(): void
    {
        $owner = User::factory()->create();

        // Multiple agents (exercises agent_comparison) each with a channel,
        // conversations spread across the window, and messages carrying every
        // kind of meta the widgets read, so no widget's query is skipped by
        // an empty-data shortcut.
        $agents = Agent::factory()->for($owner)->count(4)->create();

        foreach ($agents as $agent) {
            $channel = Channel::factory()->for($agent)->create();

            foreach (range(1, 6) as $i) {
                $at = CarbonImmutable::now()->subDays($i % 7)->subHours($i);

                $conversation = $agent->conversations()->create([
                    'status' => $i % 5 === 0 ? 'closed' : 'ai',
                    'channel_id' => $channel->id,
                    'external_user_id' => 'u'.$agent->id.'-'.$i,
                    'last_message_at' => $at,
                ]);

                $conversation->messages()->create(['role' => 'user', 'content' => 'q', 'created_at' => $at])
                    ->forceFill(['created_at' => $at])->save();

                $meta = match ($i % 4) {
                    0 => ['unanswered' => true, 'unanswered_question' => 'Доставка есть?', 'unanswered_reason' => 'catalog_miss'],
                    1 => ['product_queries' => [['query' => 'куртка', 'count' => 1]]],
                    2 => ['total_ms' => 500 + $i * 10],
                    default => [],
                };

                $conversation->messages()
                    ->create(['role' => 'assistant', 'content' => 'a', 'meta' => $meta])
                    ->forceFill([
                        'created_at' => $at,
                        'rating' => $i % 3 === 0 ? 'thumbs_down' : ($i % 2 === 0 ? 'thumbs_up' : null),
                    ])->save();
            }

            Lead::factory()->for($agent)->create(['status' => 'new']);
            Lead::factory()->for($agent)->create(['status' => 'in_progress']);
            Lead::factory()->for($agent)->create(['status' => 'done']);
            Lead::factory()->for($agent)->create([
                'type' => HagglingService::TYPE,
                'status' => HagglingService::STATUS_PENDING,
            ]);
        }

        $this->actingAs($owner);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->get(route('dashboard', ['range' => 30]));
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        fwrite(STDERR, sprintf("[dashboard-budget] status=%d queries=%d\n", $response->getStatusCode(), $count));

        $response->assertOk();
        // Measured 20 on this fixture after the forRange() de-dup fixes.
        // Comfortably under the 29 the same fixture ran before them, but
        // tight enough that a reintroduced duplicate (a re-counted total, a
        // re-queried pending()) walks straight through it.
        $this->assertLessThanOrEqual(22, $count, "/dashboard ran {$count} queries — possible regression.");
    }

    /** The index Phase 2 added to make the per-widget messages scans cheap. */
    public function test_messages_has_a_role_created_at_index(): void
    {
        $this->assertTrue(
            collect(Schema::getIndexes('messages'))->contains(
                fn ($index) => $index['columns'] === ['role', 'created_at']
            ),
            'messages is missing the (role, created_at) index /dashboard relies on.'
        );
    }
}
