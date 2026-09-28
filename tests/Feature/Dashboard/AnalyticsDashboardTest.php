<?php

namespace Tests\Feature\Dashboard;

use App\Models\Agent;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\User;
use App\Services\Analytics\DashboardMetrics;
use App\Services\Haggling\HagglingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The dashboard's numbers, and the promise underneath them: nothing on this page
 * is invented. Every assertion here builds real rows and checks the figure that
 * falls out of them.
 */
class AnalyticsDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Agent $agent;

    protected Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->agent = Agent::factory()->for($this->owner)->create();
        // One channel, reused: `channels` is unique on (agent_id, type), so an
        // agent has at most one Telegram connection by design.
        $this->channel = Channel::factory()->for($this->agent)->create(['type' => 'telegram']);
    }

    protected function metrics(int $days = 7, ?int $agentId = null): array
    {
        return (new DashboardMetrics($this->owner->fresh()))->forRange($days, $agentId);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    protected function conversationWith(int $assistantMessages = 1, array $meta = [], ?CarbonImmutable $at = null): Conversation
    {
        $at ??= CarbonImmutable::now();

        $conversation = $this->agent->conversations()->create([
            'status' => 'ai',
            'channel_id' => $this->channel->id,
            'external_user_id' => 'u'.uniqid(),
            'last_message_at' => $at,
        ]);

        for ($i = 0; $i < $assistantMessages; $i++) {
            // forceFill for the timestamp: `created_at` is not fillable on
            // Message, so passing it to create() would be silently dropped and
            // every one of these rows would land at "now".
            $conversation->messages()
                ->create(['role' => 'assistant', 'content' => 'ответ', 'meta' => $meta])
                ->forceFill(['created_at' => $at])
                ->save();
        }

        return $conversation;
    }

    // -----------------------------------------------------------------
    // The page
    // -----------------------------------------------------------------

    public function test_the_dashboard_is_the_panel_landing_page(): void
    {
        $this->actingAs($this->owner)
            ->get(route('home'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_the_dashboard_requires_a_signed_in_owner(): void
    {
        $this->get(route('dashboard'))->assertRedirect();
    }

    /**
     * An account with no history gets one honest empty state rather than a grid
     * of zeroes — and certainly rather than sample data.
     */
    public function test_an_account_with_no_conversations_sees_the_empty_state(): void
    {
        $this->actingAs($this->owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->component('Dashboard')
                    // `has_history` is the single switch the page renders the
                    // empty state from, so asserting it is asserting the state.
                    ->where('metrics.has_history', false)
            );
    }

    public function test_the_widgets_appear_once_there_is_history(): void
    {
        $this->conversationWith();

        $this->actingAs($this->owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->component('Dashboard')
                    ->where('metrics.has_history', true)
                    // Every widget's data reaches the page. The widgets
                    // themselves render client-side now, so what is assertable
                    // — and what actually matters — is that none of them is
                    // left without its numbers.
                    ->has('metrics.summary')
                    ->has('metrics.series')
                    ->has('metrics.funnel')
                    ->has('metrics.ratings')
                    ->has('metrics.response_trend')
                    ->has('metrics.leads_by_status')
                    ->has('metrics.heatmap')
                    ->has('metrics.products')
                    ->has('metrics.unanswered')
                    ->has('metrics.pending')
            );
    }

    public function test_the_range_toggle_is_honoured_and_falls_back_when_invalid(): void
    {
        $this->conversationWith();

        $this->assertSame(30, $this->metrics(30)['range']);
        $this->assertCount(30, $this->metrics(30)['series']);
        // Not one of the offered ranges — falls back rather than 500ing.
        $this->assertSame(7, (new DashboardMetrics($this->owner))->forRange(365)['range']);

        $this->actingAs($this->owner)->get(route('dashboard', ['range' => 30]))->assertOk();
    }

    // -----------------------------------------------------------------
    // Per-agent filter
    // -----------------------------------------------------------------

    /**
     * Every widget is built from the same handful of query builders
     * (conversations()/leads()/messages(), all via agentIds()) — this proves
     * the filter reaches them by testing the shared primitive plus one
     * representative widget of each kind, rather than every widget by name.
     */
    public function test_selecting_an_agent_scopes_every_widget_to_it(): void
    {
        $second = Agent::factory()->for($this->owner)->create(['name' => 'Второй']);
        $secondChannel = Channel::factory()->for($second)->create(['type' => 'telegram']);

        $this->conversationWith(1, ['total_ms' => 500]);

        $secondConversation = $second->conversations()->create([
            'status' => 'ai',
            'channel_id' => $secondChannel->id,
            'external_user_id' => 'u'.uniqid(),
            'last_message_at' => CarbonImmutable::now(),
        ]);
        $secondConversation->messages()
            ->create(['role' => 'assistant', 'content' => 'ответ', 'meta' => ['total_ms' => 900]]);
        $second->leads()->create(['items' => 'чужая заявка', 'source' => 'telegram', 'status' => 'new']);

        $all = $this->metrics();
        $this->assertSame(2, $all['summary']['conversations_total']);
        $this->assertSame(1, $all['leads_by_status']['total']);

        $scoped = $this->metrics(7, $this->agent->id);
        $this->assertSame($this->agent->id, $scoped['agent_id']);
        $this->assertSame(1, $scoped['summary']['conversations_total']);
        $this->assertSame(500.0, $scoped['summary']['avg_response_ms']);
        // The other agent's lead must not leak into this agent's breakdown.
        $this->assertSame(0, $scoped['leads_by_status']['total']);
    }

    /** Comparing an agent against itself is not a comparison. */
    public function test_the_agent_comparison_disappears_once_the_page_is_scoped_to_one_agent(): void
    {
        $second = Agent::factory()->for($this->owner)->create();
        $secondChannel = Channel::factory()->for($second)->create(['type' => 'telegram']);

        $this->conversationWith(2);

        $secondConversation = $second->conversations()->create([
            'status' => 'ai',
            'channel_id' => $secondChannel->id,
            'external_user_id' => 'u'.uniqid(),
            'last_message_at' => CarbonImmutable::now(),
        ]);
        $secondConversation->messages()->createMany(array_fill(0, 5, ['role' => 'assistant', 'content' => 'ответ']));

        $this->assertNotEmpty($this->metrics()['agent_comparison']);
        $this->assertSame([], $this->metrics(7, $this->agent->id)['agent_comparison']);
    }

    /** A stranger's agent id is ignored, not trusted — falls back to "all". */
    public function test_the_dashboard_ignores_an_agent_id_that_does_not_belong_to_the_owner(): void
    {
        $strangerAgent = Agent::factory()->create();
        $this->conversationWith();

        $this->actingAs($this->owner)
            ->get(route('dashboard', ['agent_id' => $strangerAgent->id]))
            ->assertOk()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    // The bad id was DROPPED, not honoured and not treated as
                    // "show nothing" — the owner still sees their own data.
                    ->where('metrics.agent_id', null)
                    ->where('metrics.summary.conversations_total', 1)
            );
    }

    /** Pointless with one agent — same rule the "Нагрузка по агентам" card uses. */
    public function test_the_agent_selector_is_absent_for_a_single_agent_account(): void
    {
        $this->conversationWith();

        $this->actingAs($this->owner)->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->count('agents', 1));
    }

    public function test_the_agent_selector_lists_every_agent_when_there_is_more_than_one(): void
    {
        $second = Agent::factory()->for($this->owner)->create(['name' => 'Второй агент']);
        $this->conversationWith();

        $this->actingAs($this->owner)->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->count('agents', 2)
                    ->where('agents.1.name', 'Второй агент')
            );
    }

    // -----------------------------------------------------------------
    // Scoping
    // -----------------------------------------------------------------

    public function test_another_owners_data_is_never_counted(): void
    {
        $stranger = User::factory()->create();
        $strangerAgent = Agent::factory()->for($stranger)->create();
        $strangerAgent->conversations()->create(['status' => 'ai', 'last_message_at' => now()]);
        $strangerAgent->leads()->create(['items' => 'чужая заявка', 'source' => 'telegram', 'status' => 'new']);

        $metrics = $this->metrics();

        $this->assertFalse($metrics['has_history']);
        $this->assertSame(0, $metrics['summary']['leads_today']);
        $this->assertSame(0, $metrics['summary']['conversations_total']);
    }

    // -----------------------------------------------------------------
    // The summary numbers
    // -----------------------------------------------------------------

    public function test_active_conversations_counts_only_recent_and_unclosed_ones(): void
    {
        $this->conversationWith(at: CarbonImmutable::now()->subHour());
        $this->conversationWith(at: CarbonImmutable::now()->subDays(5));            // too old
        $this->conversationWith()->update(['status' => 'closed']);                   // closed

        $this->assertSame(1, $this->metrics()['summary']['active_conversations']);
    }

    public function test_leads_today_and_this_week_are_counted_separately(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-03 12:00:00')); // a Thursday

        $this->agent->leads()->create(['items' => 'сегодня', 'source' => 'telegram', 'status' => 'new']);

        $earlier = $this->agent->leads()->create(['items' => 'в понедельник', 'source' => 'telegram', 'status' => 'new']);
        $earlier->forceFill(['created_at' => CarbonImmutable::parse('2026-08-31 10:00:00')])->save();

        $lastMonth = $this->agent->leads()->create(['items' => 'давно', 'source' => 'telegram', 'status' => 'new']);
        $lastMonth->forceFill(['created_at' => CarbonImmutable::parse('2026-08-01 10:00:00')])->save();

        $summary = $this->metrics()['summary'];

        $this->assertSame(1, $summary['leads_today']);
        $this->assertSame(2, $summary['leads_week']);
    }

    public function test_the_conversion_rate_is_the_share_of_conversations_that_produced_a_lead(): void
    {
        $converted = $this->conversationWith();
        $this->conversationWith();
        $this->conversationWith();
        $this->conversationWith();

        $this->agent->leads()->create([
            'conversation_id' => $converted->id,
            'items' => 'куртка',
            'source' => 'telegram',
            'status' => 'new',
        ]);

        $this->assertSame(25.0, $this->metrics()['summary']['conversion_rate']);
    }

    /** No replies yet means no measurement — null, never a fabricated zero. */
    public function test_the_conversion_rate_and_response_time_are_null_without_data(): void
    {
        $summary = $this->metrics()['summary'];

        $this->assertNull($summary['conversion_rate']);
        $this->assertNull($summary['avg_response_ms']);
    }

    /**
     * Read from the timing the reply pipeline already stamps on each reply,
     * rather than from the gap between two rows — which would also measure the
     * customer thinking.
     */
    public function test_average_response_time_comes_from_the_recorded_reply_timings(): void
    {
        $this->conversationWith(1, ['total_ms' => 1000]);
        $this->conversationWith(1, ['total_ms' => 3000]);

        $this->assertSame(2000.0, $this->metrics()['summary']['avg_response_ms']);
    }

    /*
     * REMOVED WITH THE BLADE STAT PARTIAL: the two number-formatting tests.
     *
     * They rendered `dashboard.partials.stat` directly and asserted that 1238
     * printed as "1 238" while a non-integer ("5.2 с") was passed through
     * untouched. That partial is gone; the same rule now lives in
     * Components/ui/StatCard.vue's formatValue(), which uses a non-breaking
     * space so the figure cannot wrap across its own separator.
     *
     * It is not re-asserted here because it is now browser-side formatting that
     * a PHP request cannot observe — the server sends the raw integer, which is
     * what `test_leads_today_and_this_week_are_counted_separately` already pins.
     */

    // -----------------------------------------------------------------
    // Working while you sleep
    // -----------------------------------------------------------------

    public function test_after_hours_counts_nights_and_weekends(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-03 12:00:00')); // Thursday noon

        $this->conversationWith(at: CarbonImmutable::parse('2026-09-03 02:30:00')); // night
        $this->conversationWith(at: CarbonImmutable::parse('2026-09-03 23:10:00')); // night
        $this->conversationWith(at: CarbonImmutable::parse('2026-08-30 14:00:00')); // Sunday afternoon
        $this->conversationWith(at: CarbonImmutable::parse('2026-09-02 14:00:00')); // Wednesday afternoon

        $afterHours = $this->metrics()['after_hours'];

        $this->assertSame(3, $afterHours['messages']);
        $this->assertSame(75.0, $afterHours['share']);
    }

    public function test_the_after_hours_window_is_configurable(): void
    {
        $metrics = new DashboardMetrics($this->owner);

        config()->set('agent.analytics.after_hours', ['from_hour' => 22, 'to_hour' => 8, 'weekends' => false]);
        $this->assertFalse($metrics->isAfterHours(CarbonImmutable::parse('2026-08-30 14:00:00')));
        $this->assertTrue($metrics->isAfterHours(CarbonImmutable::parse('2026-09-02 23:00:00')));

        config()->set('agent.analytics.after_hours', ['from_hour' => 22, 'to_hour' => 8, 'weekends' => true]);
        $this->assertTrue($metrics->isAfterHours(CarbonImmutable::parse('2026-08-30 14:00:00')));
    }

    // -----------------------------------------------------------------
    // Chart, heatmap, funnel
    // -----------------------------------------------------------------

    /** A silent day is still a day: a chart that skips them hides the quiet. */
    public function test_the_series_includes_every_day_in_the_range(): void
    {
        $this->conversationWith(2, at: CarbonImmutable::now());

        $series = $this->metrics()['series'];

        $this->assertCount(7, $series);
        $this->assertSame(0, $series[0]['total']);
        $this->assertSame(2, $series[6]['total']);
    }

    /*
     * REMOVED WITH THE BLADE CHART: three tests that asserted rendered bar
     * geometry — `test_days_with_messages_render_bars_with_a_real_height`,
     * `test_a_barely_used_day_is_not_rounded_away`, and the DOMXPath helpers
     * that read each bar's height in rem.
     *
     * They existed for a real bug: the bars were sized with a percentage height
     * inside a flex item that had been left content-sized, so a percentage
     * against an indefinite height resolved to auto and every bar rendered at
     * zero while the total above it read 168. Asserting absolute rem was the
     * fix that caught it.
     *
     * None of that can happen now. The chart is ApexCharts drawing into an SVG
     * with an explicit pixel height, so there is no percentage-against-auto to
     * collapse — and the heights are computed in the browser, which a PHP test
     * cannot see. What the bars are DRAWN FROM is still fully covered by
     * `test_the_series_includes_every_day_in_the_range` below, which is the
     * assertion that would actually fail if the data went wrong.
     */

    public function test_the_channel_breakdown_only_lists_connected_channels(): void
    {
        $this->conversationWith();

        $this->assertSame(['telegram'], $this->metrics()['channels']);
    }

    public function test_the_heatmap_buckets_messages_by_weekday_and_hour(): void
    {
        // A Wednesday at 15:00 in Khujand — ISO weekday 3, so row index 2.
        $this->conversationWith(3, at: CarbonImmutable::parse('2026-09-02 15:20:00', 'Asia/Dushanbe')->utc());
        $this->travelTo(CarbonImmutable::parse('2026-09-03 12:00:00'));

        $heatmap = $this->metrics()['heatmap'];

        $this->assertSame(3, $heatmap['grid'][2][15]);
        $this->assertSame(3, $heatmap['max']);
        $this->assertCount(7, $heatmap['grid']);
        $this->assertCount(24, $heatmap['grid'][0]);
    }

    /**
     * Stored in UTC, drawn on the shop's clock: 20:30 UTC on a Tuesday is
     * 01:30 on Wednesday in Khujand, and that is the cell it belongs in.
     */
    public function test_the_heatmap_reads_hours_in_the_shop_timezone(): void
    {
        $this->conversationWith(2, at: CarbonImmutable::parse('2026-09-01 20:30:00'));
        $this->travelTo(CarbonImmutable::parse('2026-09-03 12:00:00'));

        $grid = $this->metrics()['heatmap']['grid'];

        $this->assertSame(2, $grid[2][1]);
        $this->assertSame(0, $grid[1][20]);
    }

    /** The owner is told which clock the grid is drawn on. */
    public function test_the_heatmap_shows_which_timezone_it_is_drawn_in(): void
    {
        $this->conversationWith();

        $this->actingAs($this->owner)->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->has('metrics.heatmap.grid')
                    // The label the card prints comes from the shared
                    // translations, so this is what "the owner is told which
                    // clock it is drawn on" reduces to server-side.
                    ->where('translations.dashboard.heatmap.timezone_label', __('dashboard.heatmap.timezone_label'))
            );
    }

    /** 18:00 UTC is 23:00 in Khujand — the customer wrote at night. */
    public function test_after_hours_is_judged_on_the_shop_clock(): void
    {
        $metrics = new DashboardMetrics($this->owner);

        $this->assertTrue($metrics->isAfterHours(CarbonImmutable::parse('2026-09-02 18:00:00')));
        $this->assertFalse($metrics->isAfterHours(CarbonImmutable::parse('2026-09-02 05:00:00')));

        config()->set('agent.analytics.timezone', 'UTC');
        $this->assertFalse($metrics->isAfterHours(CarbonImmutable::parse('2026-09-02 18:00:00')));
    }

    public function test_the_funnel_reports_each_stage_and_its_conversion(): void
    {
        $converted = $this->conversationWith();
        $this->conversationWith();
        $this->conversationWith();
        $this->conversationWith();

        $this->agent->leads()->create(['conversation_id' => $converted->id, 'items' => 'a', 'source' => 'telegram', 'status' => 'new']);
        $this->agent->leads()->create(['items' => 'b', 'source' => 'telegram', 'status' => 'done']);

        [$conversations, $leads, $confirmed] = $this->metrics()['funnel'];

        $this->assertSame(4, $conversations['value']);
        $this->assertNull($conversations['conversion']);
        $this->assertSame(2, $leads['value']);
        $this->assertSame(50.0, $leads['conversion']);
        // Only the `done` one counts as confirmed — a lead still at `new` is a
        // lead, not an order.
        $this->assertSame(1, $confirmed['value']);
        $this->assertSame(50.0, $confirmed['conversion']);
    }

    // -----------------------------------------------------------------
    // Pending work
    // -----------------------------------------------------------------

    public function test_pending_orders_and_price_approvals_are_counted_apart(): void
    {
        $this->agent->leads()->create(['items' => 'a', 'source' => 'telegram', 'status' => 'new']);
        $this->agent->leads()->create(['items' => 'b', 'source' => 'telegram', 'status' => 'in_progress']);
        $this->agent->leads()->create(['items' => 'c', 'source' => 'telegram', 'status' => 'done']);
        $this->agent->leads()->create([
            'type' => HagglingService::TYPE,
            'items' => 'ноутбук',
            'source' => 'telegram',
            'status' => HagglingService::STATUS_PENDING,
        ]);

        $pending = $this->metrics()['pending'];

        $this->assertSame(2, $pending['open_orders']);
        $this->assertSame(1, $pending['price_approvals']);
    }

    public function test_the_pending_widget_links_to_each_filtered_view(): void
    {
        $this->conversationWith();
        $this->agent->leads()->create(['items' => 'a', 'source' => 'telegram', 'status' => 'new']);

        $this->actingAs($this->owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    // The hrefs are built in the browser from these counts by
                    // Components/dashboard/PendingCard.vue, which links each to
                    // the leads screen FILTERED to exactly those leads. The
                    // server-side half of that promise is the counts.
                    ->where('metrics.pending.open_orders', 1)
                    ->where('metrics.pending.price_approvals', 0)
            );
    }

    // -----------------------------------------------------------------
    // Demand: asked-for products, and questions with no answer
    // -----------------------------------------------------------------

    public function test_most_asked_products_aggregate_searches_regardless_of_sale(): void
    {
        $this->conversationWith(1, ['product_queries' => [
            ['query' => 'ноутбук', 'category' => null, 'count' => 3],
        ]]);
        $this->conversationWith(1, ['product_queries' => [
            ['query' => 'Ноутбук', 'category' => null, 'count' => 0],
            ['query' => 'мышка', 'category' => null, 'count' => 2],
        ]]);

        $products = $this->metrics()['products'];

        // Case-folded into one row, labelled with the most recent spelling, and
        // counted per search rather than per sale.
        $this->assertSame('Ноутбук', $products[0]['term']);
        $this->assertSame(2, $products[0]['count']);
        // One of the two searches found nothing — a stocking signal.
        $this->assertSame(50, $products[0]['miss_rate']);
        $this->assertSame('мышка', $products[1]['term']);
    }

    /** A bare "what do you sell?" browse carries no demand signal. */
    public function test_a_filterless_browse_is_not_counted_as_a_product_question(): void
    {
        $this->conversationWith(1, ['product_queries' => [['query' => null, 'category' => null, 'count' => 5]]]);

        $this->assertSame([], $this->metrics()['products']);
    }

    public function test_unanswered_questions_are_grouped_by_what_was_asked(): void
    {
        $this->conversationWith(1, [
            'unanswered' => true,
            'unanswered_reason' => 'catalog_miss',
            'unanswered_question' => 'Есть ли доставка в Худжанд?',
        ]);
        $this->conversationWith(1, [
            'unanswered' => true,
            'unanswered_reason' => 'catalog_miss',
            'unanswered_question' => 'есть ли доставка в худжанд',
        ]);
        $this->conversationWith(1, [
            'unanswered' => true,
            'unanswered_reason' => 'knowledge_miss',
            'unanswered_question' => 'Работаете в воскресенье?',
        ]);
        $this->conversationWith(1, ['unanswered' => false]);

        $unanswered = $this->metrics()['unanswered'];

        $this->assertCount(2, $unanswered);
        // Punctuation and case fold together into one row; the phrasing shown is
        // a real one the customer typed — the most recent of them.
        $this->assertSame('есть ли доставка в худжанд', $unanswered[0]['question']);
        $this->assertSame(2, $unanswered[0]['count']);
        $this->assertSame('catalog_miss', $unanswered[0]['reason']);
        $this->assertSame(1, $unanswered[1]['count']);
    }

    // -----------------------------------------------------------------
    // i18n
    // -----------------------------------------------------------------

    public function test_the_dashboard_translates_across_every_supported_locale(): void
    {
        $this->conversationWith();

        foreach (['ru', 'en', 'tg'] as $locale) {
            // Set on the account rather than with app()->setLocale(): the
            // SetLocale middleware re-resolves the locale on every request, so
            // a container-level override would not survive the trip.
            $this->owner->forceFill(['locale' => $locale])->save();

            $this->actingAs($this->owner)
                ->get(route('dashboard'))
                ->assertOk()
                ->assertInertia(
                    fn (AssertableInertia $page) => $page
                        ->where('locale.current', $locale)
                        ->where(
                            'translations.dashboard.highlight.eyebrow',
                            __('dashboard.highlight.eyebrow', [], $locale)
                        )
                );
        }
    }
}
