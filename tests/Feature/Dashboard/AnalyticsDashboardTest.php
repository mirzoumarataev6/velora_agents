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
        $response = $this->actingAs($this->owner)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee(__('dashboard.empty.title'), false);
        $response->assertSee(__('dashboard.empty.description'), false);
        $response->assertDontSee('analytics-more-toggle', false);
    }

    public function test_the_widgets_appear_once_there_is_history(): void
    {
        $this->conversationWith();

        $response = $this->actingAs($this->owner)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee(__('dashboard.highlight.eyebrow'), false);
        $response->assertSee(__('dashboard.ratings.title'), false);
        $response->assertSee(__('dashboard.funnel.title'), false);
        $response->assertSee(__('dashboard.response_trend.title'), false);
        $response->assertSee(__('dashboard.leads_status.title'), false);
        $response->assertSee(__('dashboard.heatmap.title'), false);
        $response->assertSee(__('dashboard.products.title'), false);
        $response->assertSee(__('dashboard.unanswered.title'), false);
        $response->assertDontSee('analytics-more-toggle', false);
        $response->assertDontSee(__('dashboard.empty.title'), false);
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

        $response = $this->actingAs($this->owner)
            ->get(route('dashboard', ['agent_id' => $strangerAgent->id]))
            ->assertOk();

        // Still sees their own conversation — the bad id was dropped, not
        // treated as "show nothing".
        $response->assertSee(__('dashboard.funnel.title'), false);
    }

    /** Pointless with one agent — same rule the "Нагрузка по агентам" card uses. */
    public function test_the_agent_selector_is_absent_for_a_single_agent_account(): void
    {
        $this->conversationWith();

        $this->actingAs($this->owner)->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('dashboard_agent_filter', false);
    }

    public function test_the_agent_selector_lists_every_agent_when_there_is_more_than_one(): void
    {
        $second = Agent::factory()->for($this->owner)->create(['name' => 'Второй агент']);
        $this->conversationWith();

        $this->actingAs($this->owner)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('dashboard_agent_filter', false)
            ->assertSee('Второй агент', false);
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

    // -----------------------------------------------------------------
    // Number formatting
    // -----------------------------------------------------------------

    /**
     * A space thousands separator (1 238, not 1238), and the count-up
     * animation still gets a bare, parseable integer — see app.js's
     * countUpNumbers(), which reads data-countup with Number() and restores
     * this exact formatted string once the animation ends.
     */
    public function test_a_stat_cards_large_number_gets_a_thousands_separator(): void
    {
        $html = view('dashboard.partials.stat', ['label' => 'Test', 'value' => 1238])->render();

        $this->assertStringContainsString('1 238', $html);
        $this->assertStringContainsString('data-countup="1238"', $html);
    }

    /** A value that isn't a plain int (a dash, a percentage) is shown as-is. */
    public function test_a_non_integer_stat_value_is_not_reformatted(): void
    {
        $html = view('dashboard.partials.stat', ['label' => 'Test', 'value' => '5.2 с'])->render();

        $this->assertStringContainsString('5.2 с', $html);
        $this->assertStringNotContainsString('data-countup', $html);
    }

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

    /**
     * The chart has to plot the real series, not merely exist.
     *
     * This replaces a pair of tests that measured bar heights in rem. The
     * chart is a smooth area chart now — seven fat bars on a mostly empty
     * field were the loudest object on the dashboard and carried seven
     * numbers — so the geometry being asserted is the path's own on-curve
     * points instead. The guarantee is the same one and it still matters:
     * the busiest day must sit at the top of the plot, a silent day must sit
     * on the baseline, and a day in between must land proportionally.
     *
     * The original bug this guards against is worth restating, because the
     * new implementation could reproduce it exactly: the first bar chart
     * sized its bars with a percentage height against an indefinite parent,
     * so every bar resolved to zero and the chart looked empty while the
     * total above it read 168. Asserting the rendered coordinates rather than
     * the metrics array is what catches that class of fault.
     */
    public function test_the_chart_plots_each_day_at_its_real_height(): void
    {
        $today = CarbonImmutable::now()->startOfDay()->addHours(12);

        $this->conversationWith(8, at: $today);
        $this->conversationWith(2, at: $today->subDays(2));

        $chart = $this->renderedChart();
        $points = $chart['points'];

        /* The plot is drawn top-down in SVG coordinates — y=0 is the ceiling
           and a bigger value is a SMALLER y — and it is inset by a few units
           at top and bottom so the end dots and the stroke are not clipped by
           the viewBox. So the baseline is wherever the empty days sit, not
           literally the viewBox height, and every assertion below is relative
           to it rather than to an absolute coordinate. That also keeps the
           test from re-encoding the padding and the axis-ceiling rounding,
           neither of which it is here to pin. */
        $baseline = max($points);

        $this->assertEqualsWithDelta($baseline, $points[0], 0.01, 'A day with no messages should sit on the baseline.');
        $this->assertLessThan($baseline, $points[4], 'A day with messages must be lifted off the baseline.');
        $this->assertSame(min($points), $points[6], 'The busiest day should be the highest point on the chart.');

        // Two messages against a peak of eight is a quarter of the height.
        $this->assertEqualsWithDelta(
            0.25,
            ($baseline - $points[4]) / ($baseline - $points[6]),
            0.001,
            'Days must be plotted in proportion to each other.'
        );

        // And the values themselves are readable, exactly, on hover.
        $this->assertSame(8, $chart['values'][$today->format('d.m')]);
        $this->assertSame(2, $chart['values'][$today->subDays(2)->format('d.m')]);
        $this->assertSame(0, $chart['values'][$today->subDays(6)->format('d.m')]);
    }

    /**
     * A single message against a busy peak still has to be seeable.
     *
     * The bar chart met this with a minimum bar height, because a bar 1/200
     * of a track tall rounds away to nothing. An area chart meets it
     * differently and more honestly: the line is a 2px stroke at every point,
     * so it is visible wherever it runs, the point is plotted at its true
     * position rather than a floored one, and the exact count is in the
     * hover label. What must NOT happen is the quiet day collapsing onto the
     * baseline and reading as a day with no traffic at all.
     */
    public function test_a_barely_used_day_is_distinguishable_from_a_silent_one(): void
    {
        $today = CarbonImmutable::now()->startOfDay()->addHours(12);

        $this->conversationWith(200, at: $today);
        $this->conversationWith(1, at: $today->subDays(1));

        $chart = $this->renderedChart();

        $quiet = $chart['points'][5];
        $baseline = max($chart['points']);

        $this->assertEqualsWithDelta($baseline, $chart['points'][0], 0.01);
        $this->assertLessThan($baseline, $quiet, 'A day with one message must not sit on the baseline with the empty days.');
        $this->assertSame(1, $chart['values'][$today->subDays(1)->format('d.m')], 'The exact count must survive to the hover label.');
        $this->assertSame(0, $chart['values'][$today->subDays(6)->format('d.m')]);
    }

    /**
     * The messages chart, read back out of the rendered SVG.
     *
     * Returns the on-curve y coordinate per day (oldest first), the plot
     * height they are measured against, and the exact value each point
     * carries in its hover label, keyed by the day label the chart prints.
     *
     * @return array{points: list<float>, height: float, values: array<string, int>}
     */
    protected function renderedChart(): array
    {
        $html = $this->actingAs($this->owner)->get(route('dashboard'))->assertOk()->getContent();

        $document = new \DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();

        $xpath = new \DOMXPath($document);

        $line = $xpath->query("//*[contains(@class, 'chart-line')]")->item(0);
        $this->assertInstanceOf(\DOMElement::class, $line, 'The chart rendered no series path at all.');

        $svg = $line->parentNode;
        while ($svg instanceof \DOMElement && $svg->tagName !== 'svg') {
            $svg = $svg->parentNode;
        }
        $this->assertInstanceOf(\DOMElement::class, $svg);

        // "0 0 1000 200" — the height the coordinates are expressed against.
        //
        // Read case-insensitively: DOMDocument's HTML parser lowercases every
        // attribute name, so the SVG's camelCase `viewBox` arrives as
        // `viewbox` and getAttribute('viewBox') returns an empty string.
        $viewBoxAttr = $svg->getAttribute('viewBox') ?: $svg->getAttribute('viewbox');
        $this->assertNotSame('', $viewBoxAttr, 'The chart must declare a viewBox.');

        $viewBox = preg_split('/\s+/', trim($viewBoxAttr));
        $this->assertCount(4, $viewBox, "Unparseable viewBox: {$viewBoxAttr}");
        $height = (float) $viewBox[3];
        $this->assertGreaterThan(0, $height, 'The chart must declare a viewBox for its points to mean anything.');

        // The on-curve points of "M x,y C c1 c2 x,y C c1 c2 x,y …": the
        // initial moveto, then the last coordinate pair of every curveto.
        // The control points in between are smoothing and say nothing about
        // the data.
        $d = $line->getAttribute('d');
        $this->assertNotSame('', $d, 'The series path is empty.');

        preg_match('/^M\s*([\d.-]+),([\d.-]+)/', $d, $move);
        $this->assertNotEmpty($move, "Unparseable path: {$d}");

        $points = [(float) $move[2]];
        preg_match_all('/C[^C]*?\s([\d.-]+),([\d.-]+)(?=C|$)/', $d, $curves, PREG_SET_ORDER);
        foreach ($curves as $curve) {
            $points[] = (float) $curve[2];
        }

        // The hover targets carry "<label> — <value>" as their <title>.
        $values = [];
        foreach ($xpath->query("//*[contains(@class, 'chart-hit')]/*[local-name()='title']") as $title) {
            [$label, $value] = array_pad(explode(' — ', $title->textContent, 2), 2, '0');
            $values[trim($label)] = (int) trim($value);
        }

        $this->assertCount(count($values), $points, 'Every plotted point must have a hover label, and vice versa.');

        return ['points' => $points, 'height' => $height, 'values' => $values];
    }

    /** The `height` of one element, in rem — and never in percent. */
    protected function remHeight(?\DOMNode $node): float
    {
        $this->assertInstanceOf(\DOMElement::class, $node);

        $style = $node->getAttribute('style');

        $this->assertMatchesRegularExpression(
            '/height:\s*[\d.]+rem/',
            $style,
            "Expected an absolute height, got: {$style}",
        );

        preg_match('/height:\s*([\d.]+)rem/', $style, $matches);

        return (float) $matches[1];
    }

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
            ->assertSee(__('dashboard.heatmap.timezone_label'), false);
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

    /**
     * Both queues are seeded, because the widget now hides a row whose count
     * is zero rather than showing a lonely "0" — a card of noughts tells an
     * owner nothing, and the empty case gets one compact "all clear" line
     * instead. The contract under test is unchanged: a row links straight to
     * its own filtered view, so clicking a count lands on those rows rather
     * than making the owner filter for them again by hand.
     */
    public function test_the_pending_widget_links_to_each_filtered_view(): void
    {
        $this->conversationWith();
        $this->agent->leads()->create(['items' => 'a', 'source' => 'telegram', 'type' => 'order', 'status' => 'new']);
        $this->agent->leads()->create([
            'items' => 'b',
            'source' => 'telegram',
            'type' => HagglingService::TYPE,
            'status' => HagglingService::STATUS_PENDING,
        ]);

        $this->actingAs($this->owner)
            ->get(route('dashboard'))
            ->assertOk()
            // Escaped, because Blade escapes the & in a query string when it
            // writes the href.
            ->assertSee(route('leads.index', ['status' => 'new']))
            ->assertSee(route('leads.index', ['view' => 'haggle', 'status' => HagglingService::STATUS_PENDING]));
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
                ->assertSee(__('dashboard.highlight.eyebrow', [], $locale), false);
        }
    }
}
