<?php

namespace Tests\Feature\Dashboard;

use App\Models\Agent;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\User;
use App\Services\Analytics\DashboardMetrics;
use App\Services\Haggling\HagglingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The widgets added in the dashboard overhaul: the "what to do now" card that
 * replaced «Работает, пока вы спите», the clarified funnel, and the three new
 * charts.
 *
 * Every assertion here builds real rows first. The empty-state discipline is
 * itself under test: a widget with no data must say so rather than draw a zero.
 */
class DashboardWidgetsTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Agent $agent;

    protected Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->agent = Agent::factory()->for($this->owner)->create(['name' => 'Первый']);
        $this->channel = Channel::factory()->for($this->agent)->create(['type' => 'telegram']);
    }

    protected function metrics(int $days = 7): array
    {
        return (new DashboardMetrics($this->owner->fresh()))->forRange($days);
    }

    protected function page(): TestResponse
    {
        return $this->actingAs($this->owner)->get(route('dashboard'))->assertOk();
    }

    /**
     * A conversation with $replies assistant turns, optionally rated.
     */
    protected function conversationWith(
        int $replies = 1,
        array $meta = [],
        ?CarbonImmutable $at = null,
        ?string $rating = null,
        ?Agent $agent = null,
    ): Conversation {
        $at ??= CarbonImmutable::now();
        $agent ??= $this->agent;

        $conversation = $agent->conversations()->create([
            'status' => 'ai',
            'channel_id' => $this->channel->agent_id === $agent->id ? $this->channel->id : null,
            'external_user_id' => 'u'.uniqid(),
            'last_message_at' => $at,
        ]);

        for ($i = 0; $i < $replies; $i++) {
            $message = $conversation->messages()->create(['role' => 'assistant', 'content' => 'ответ', 'meta' => $meta]);
            // created_at is not fillable, so it has to be forced after the fact.
            $message->forceFill(array_filter([
                'created_at' => $at,
                'rating' => $rating,
                'rated_at' => $rating ? $at : null,
            ]))->save();
        }

        return $conversation;
    }

    // -----------------------------------------------------------------
    // The card that replaced «Работает, пока вы спите»
    // -----------------------------------------------------------------

    /** The retired card must be gone, not merely unlinked. */
    public function test_the_working_while_you_sleep_card_is_no_longer_rendered(): void
    {
        $this->conversationWith();

        $this->page()->assertDontSee(__('dashboard.after_hours.title'), false);
        $this->assertFileDoesNotExist(resource_path('views/dashboard/partials/after-hours.blade.php'));
    }

    /**
     * The ranking is by urgency first: someone actively waiting on a decision
     * outranks a pattern in last week's data, however large that pattern is.
     */
    public function test_a_customer_waiting_on_a_price_decision_outranks_everything_else(): void
    {
        $this->conversationWith(1, ['unanswered' => true, 'unanswered_question' => 'Есть доставка?']);
        $this->conversationWith(1, at: CarbonImmutable::now(), rating: Message::RATING_DOWN);

        Lead::factory()->for($this->agent)->create([
            'type' => HagglingService::TYPE,
            'status' => HagglingService::STATUS_PENDING,
        ]);

        $highlight = $this->metrics()['highlight'];

        $this->assertSame('price_approvals', $highlight['kind']);
        $this->assertSame(1, $highlight['count']);
        $this->page()->assertSee(__('dashboard.highlight.price_approvals', ['count' => 1]), false);
    }

    /**
     * With nobody waiting, a confidently wrong answer is the most important
     * thing on the page — it is the only failure nothing else here can see.
     */
    public function test_a_thumbs_down_outranks_an_unanswered_question(): void
    {
        $this->conversationWith(1, ['unanswered' => true, 'unanswered_question' => 'Есть доставка?']);
        $this->conversationWith(1, rating: Message::RATING_DOWN);

        $this->assertSame('thumbs_down', $this->metrics()['highlight']['kind']);
    }

    public function test_a_repeated_unanswered_question_is_named_in_the_card(): void
    {
        foreach (range(1, 3) as $i) {
            $this->conversationWith(1, [
                'unanswered' => true,
                'unanswered_reason' => 'knowledge_miss',
                'unanswered_question' => 'Есть ли доставка в Худжанд?',
            ]);
        }

        $highlight = $this->metrics()['highlight'];

        $this->assertSame('unanswered', $highlight['kind']);
        $this->assertSame(3, $highlight['count']);
        $this->assertSame('Есть ли доставка в Худжанд?', $highlight['subject']);

        // The card says the thing to do, not just the number.
        $this->page()->assertSee(__('dashboard.highlight.unanswered_action'), false);
    }

    public function test_open_orders_are_the_fallback_when_nothing_is_wrong(): void
    {
        $this->conversationWith();
        Lead::factory()->for($this->agent)->create(['type' => 'order', 'status' => 'new']);

        $this->assertSame('open_orders', $this->metrics()['highlight']['kind']);
    }

    /** Nothing to do is a real state, and it says so rather than showing a gap. */
    public function test_a_clean_account_gets_an_all_clear_rather_than_an_empty_card(): void
    {
        $this->conversationWith();

        $this->assertNull($this->metrics()['highlight']);
        $this->page()->assertSee(__('dashboard.highlight.clear_title'), false);
    }

    // -----------------------------------------------------------------
    // The funnel's clarity problem
    // -----------------------------------------------------------------

    public function test_the_funnel_explains_what_it_shows_and_what_each_stage_means(): void
    {
        $this->conversationWith();

        $page = $this->page();

        $page->assertSee(__('dashboard.funnel.description'), false);

        // Every stage is defined, not only the last one.
        $page->assertSee(__('dashboard.funnel.conversations_hint'), false);
        $page->assertSee(__('dashboard.funnel.leads_hint'), false);
        $page->assertSee(__('dashboard.funnel.confirmed_hint'), false);
    }

    /** The drop-off reads as a loss to act on, not as a bare number. */
    public function test_the_drop_off_is_framed_as_a_loss_at_that_stage(): void
    {
        $this->conversationWith();
        $this->conversationWith();

        Lead::factory()->for($this->agent)->create(['status' => 'new']);

        $funnel = collect($this->metrics()['funnel'])->firstWhere('key', 'leads');

        $this->assertSame(50.0, $funnel['drop_off']);
        $this->page()->assertSee(__('dashboard.funnel.drop_off', ['percent' => 50.0]), false);
    }

    // -----------------------------------------------------------------
    // Ratings
    // -----------------------------------------------------------------

    public function test_ratings_are_counted_and_scored(): void
    {
        $this->conversationWith(3, rating: Message::RATING_UP);
        $this->conversationWith(1, rating: Message::RATING_DOWN);

        $ratings = $this->metrics()['ratings'];

        $this->assertSame(3, $ratings['up']);
        $this->assertSame(1, $ratings['down']);
        $this->assertSame(75.0, $ratings['score']);
    }

    /**
     * An agent nobody has rated has not scored perfectly — it has not been
     * scored. The distinction is the difference between a useful widget and a
     * flattering one.
     */
    public function test_an_unrated_agent_scores_null_rather_than_a_hundred_percent(): void
    {
        $this->conversationWith(5);

        $this->assertNull($this->metrics()['ratings']['score']);
        $this->page()->assertSee(__('dashboard.ratings.empty'), false);
    }

    /** The point of per-message ratings: which reply, not how many. */
    public function test_the_disliked_replies_are_listed_with_a_link_to_their_conversation(): void
    {
        $conversation = $this->conversationWith(1, rating: Message::RATING_DOWN);

        $worst = $this->metrics()['ratings']['worst'];

        $this->assertCount(1, $worst);
        $this->assertSame($conversation->id, $worst[0]['conversation_id']);

        $this->page()->assertSee(
            route('conversations.index', ['conversation' => $conversation->id]),
            false,
        );
    }

    /** Bars must have a real height, the way the messages chart does. */
    public function test_the_ratings_chart_draws_bars_with_a_real_height(): void
    {
        $this->conversationWith(2, at: CarbonImmutable::now(), rating: Message::RATING_UP);

        $html = $this->page()->getContent();

        $this->assertMatchesRegularExpression(
            '/bg-success[^"]*"\s+style="height: (?!0rem)[\d.]+rem"/',
            $html,
            'A rated day should draw a bar with a non-zero height.',
        );
    }

    /**
     * The card has to explain itself. A judge — or an owner — meeting this for
     * the first time needs one line on what it is before the numbers mean
     * anything, and that line is the reason the card exists rather than a
     * caption on a chart.
     */
    public function test_the_quality_card_explains_what_it_is_and_where_ratings_come_from(): void
    {
        $this->conversationWith(1, rating: Message::RATING_UP);

        $page = $this->page();

        $page->assertSee(__('dashboard.ratings.title'), false);
        $page->assertSee(__('dashboard.ratings.description'), false);
        $page->assertSee(__('dashboard.ratings.telegram_only'), false);
    }

    /** The percentage AND a visual for it, not one or the other. */
    public function test_the_score_is_shown_as_both_a_ring_and_a_percentage(): void
    {
        $this->conversationWith(3, rating: Message::RATING_UP);
        $this->conversationWith(1, rating: Message::RATING_DOWN);

        $html = $this->page()->getContent();

        // The ring is a conic gradient cut to 75% success.
        $this->assertStringContainsString('conic-gradient(var(--color-success) 0 75%', $html);
        // And the same number in figures, wired to the count-up convention.
        $this->assertStringContainsString('data-countup="75"', $html);
        $this->assertStringContainsString('data-countup-suffix="%"', $html);
    }

    public function test_the_totals_and_split_are_shown(): void
    {
        $this->conversationWith(3, rating: Message::RATING_UP);
        $this->conversationWith(1, rating: Message::RATING_DOWN);

        $page = $this->page();

        $page->assertSee(__('dashboard.ratings.total'), false);
        $page->assertSee(__('dashboard.ratings.up'), false);
        $page->assertSee(__('dashboard.ratings.down'), false);
        $page->assertSee(__('dashboard.ratings.trend_title'), false);
    }

    /**
     * The link that makes the card a task rather than a report. It must point
     * at the inbox filtered to these replies, not at the inbox in general.
     */
    public function test_the_card_links_to_the_inbox_filtered_to_negative_ratings(): void
    {
        $this->conversationWith(1, rating: Message::RATING_DOWN);

        $this->page()
            ->assertSee(__('dashboard.ratings.view_negative'), false)
            ->assertSee(route('conversations.index', ['status' => 'rated_down']), false);
    }

    /** With ratings but no bad ones, there is nothing to go and look at. */
    public function test_the_negative_link_is_absent_when_nothing_was_disliked(): void
    {
        $this->conversationWith(2, rating: Message::RATING_UP);

        $this->page()
            ->assertSee(__('dashboard.ratings.no_negative'), false)
            ->assertDontSee(__('dashboard.ratings.view_negative'), false);
    }

    /**
     * The empty state has to teach, not just apologise: someone looking at a
     * fresh account should still learn what this card will show and how the
     * numbers get collected.
     */
    public function test_the_empty_state_explains_how_ratings_are_collected(): void
    {
        $this->conversationWith(3);

        $page = $this->page();

        $page->assertSee(__('dashboard.ratings.empty_title'), false);
        $page->assertSee(__('dashboard.ratings.empty_how_title'), false);

        foreach (__('dashboard.ratings.empty_how') as $step) {
            $page->assertSee($step, false);
        }

        // And still no ring, no chart and above all no flattering 100%.
        $page->assertDontSee('conic-gradient', false);
    }

    // -----------------------------------------------------------------
    // Response-time trend
    // -----------------------------------------------------------------

    public function test_the_response_trend_averages_measured_replies_per_day(): void
    {
        $today = CarbonImmutable::now()->startOfDay()->addHours(10);

        $this->conversationWith(1, ['total_ms' => 400], $today);
        $this->conversationWith(1, ['total_ms' => 600], $today);

        $series = $this->metrics()['response_trend']['series'];
        $todayRow = collect($series)->firstWhere('date', $today->toDateString());

        $this->assertSame(500.0, (float) $todayRow['ms']);
        $this->assertSame(2, $todayRow['replies']);
    }

    /** A quiet day is not an instant one: it is a gap, not a zero. */
    public function test_a_day_with_no_replies_carries_null_rather_than_zero(): void
    {
        $this->conversationWith(1, ['total_ms' => 400], CarbonImmutable::now());

        $series = $this->metrics()['response_trend']['series'];

        $this->assertNull($series[0]['ms']);
        $this->assertNotNull(end($series)['ms']);
    }

    /**
     * The chart's height must come from what it is, not from what sits beside
     * it. It used to be `flex-1`, so it absorbed every spare pixel of a grid
     * row whose height is set by the heatmap — and a two-point line was drawn
     * over the best part of a screen.
     */
    public function test_the_trend_chart_area_is_capped_rather_than_filling_the_row(): void
    {
        $today = CarbonImmutable::now()->startOfDay()->addHours(10);
        $this->conversationWith(1, ['total_ms' => 400], $today);
        $this->conversationWith(1, ['total_ms' => 900], $today->subDays(2));

        $html = $this->page()->getContent();

        // Constrained the same way every other chart here is: an explicit rem
        // height on the drawing area.
        $this->assertStringContainsString('style="height: 7rem"', $html);
        $this->assertStringNotContainsString('style="min-height: 7rem"', $html);
    }

    /**
     * The dots are HTML rather than <circle> because preserveAspectRatio="none"
     * scales x and y differently — nothing protects a filled shape from that,
     * so SVG circles were drawn as ellipses, flatter the shorter the chart.
     */
    public function test_the_trend_data_points_stay_round_at_the_smaller_size(): void
    {
        $today = CarbonImmutable::now()->startOfDay()->addHours(10);
        $this->conversationWith(1, ['total_ms' => 400], $today);
        $this->conversationWith(1, ['total_ms' => 900], $today->subDays(2));

        $html = $this->page()->getContent();

        $this->assertMatchesRegularExpression('/rounded-full bg-brand"\s+style="left: [\d.]+%; top: [\d.]+%"/', $html);
        // The old distorted dot, by its distinctive radius.
        $this->assertStringNotContainsString('r="3.5"', $html);
    }

    /** A single measured day still draws its point, and both axis labels. */
    public function test_a_single_data_point_still_renders(): void
    {
        $today = CarbonImmutable::now()->startOfDay()->addHours(10);
        $this->conversationWith(1, ['total_ms' => 400], $today);

        $html = $this->page()->getContent();

        // The dot is there; the line and its fill are correctly absent.
        $this->assertMatchesRegularExpression('/rounded-full bg-brand"\s+style="left:/', $html);
        $this->assertStringNotContainsString('<polyline', $html);

        // And the labels either side of the range survive.
        $this->assertStringContainsString(CarbonImmutable::now()->subDays(6)->format('d.m'), $html);
        $this->assertStringContainsString($today->format('d.m'), $html);
    }

    public function test_the_response_trend_says_so_when_nothing_has_been_measured(): void
    {
        $this->conversationWith(1);

        $this->assertFalse($this->metrics()['response_trend']['has_data']);
        $this->page()->assertSee(__('dashboard.response_trend.empty'), false);
    }

    // -----------------------------------------------------------------
    // Leads by status
    // -----------------------------------------------------------------

    public function test_leads_are_broken_down_by_status_with_shares(): void
    {
        $this->conversationWith();

        Lead::factory()->count(3)->for($this->agent)->create(['status' => 'new']);
        Lead::factory()->for($this->agent)->create(['status' => 'done']);

        $breakdown = $this->metrics()['leads_by_status'];
        $byStatus = collect($breakdown['rows'])->keyBy('status');

        $this->assertSame(4, $breakdown['total']);
        $this->assertSame(3, $byStatus['new']['count']);
        $this->assertSame(75.0, $byStatus['new']['share']);
        $this->assertSame(1, $byStatus['done']['count']);
    }

    /**
     * "Ждут согласования цены: 0" tells the owner nobody is waiting on them.
     * Dropping the row would just make the list look shorter.
     */
    public function test_statuses_with_no_leads_are_still_listed(): void
    {
        $this->conversationWith();
        Lead::factory()->for($this->agent)->create(['status' => 'new']);

        $statuses = array_column($this->metrics()['leads_by_status']['rows'], 'status');

        $this->assertSame(['new', 'in_progress', HagglingService::STATUS_PENDING, 'done'], $statuses);
        $this->page()->assertSee(__('dashboard.leads_status.statuses.awaiting_price_approval'), false);
    }

    public function test_the_leads_breakdown_says_so_when_there_are_none(): void
    {
        $this->conversationWith();

        $this->assertSame(0, $this->metrics()['leads_by_status']['total']);
        $this->page()->assertSee(__('dashboard.leads_status.empty'), false);
    }

    // -----------------------------------------------------------------
    // Busiest agent
    // -----------------------------------------------------------------

    /** A comparison of one thing is not a comparison. */
    public function test_the_agent_comparison_is_absent_for_a_single_agent_account(): void
    {
        $this->conversationWith(3);

        $this->assertSame([], $this->metrics()['agent_comparison']);
        $this->page()->assertDontSee(__('dashboard.agents_compare.title'), false);
    }

    public function test_the_agent_comparison_ranks_agents_by_volume(): void
    {
        $second = Agent::factory()->for($this->owner)->create(['name' => 'Второй']);

        $this->conversationWith(2);
        $this->conversationWith(5, agent: $second);

        $comparison = $this->metrics()['agent_comparison'];

        $this->assertCount(2, $comparison);
        $this->assertSame('Второй', $comparison[0]['name']);
        $this->assertSame(5, $comparison[0]['messages']);
        $this->assertSame(2, $comparison[1]['messages']);

        $this->page()->assertSee(__('dashboard.agents_compare.title'), false);
    }

    // -----------------------------------------------------------------
    // Scoping — the new widgets must respect it like the old ones
    // -----------------------------------------------------------------

    public function test_another_owners_ratings_and_leads_are_never_counted(): void
    {
        $stranger = User::factory()->create();
        $strangerAgent = Agent::factory()->for($stranger)->create();
        $strangerConversation = $strangerAgent->conversations()->create(['status' => 'ai']);
        $strangerConversation->messages()
            ->create(['role' => 'assistant', 'content' => 'чужой ответ'])
            ->forceFill(['rating' => Message::RATING_DOWN, 'rated_at' => now()])
            ->save();

        Lead::factory()->for($strangerAgent)->create(['status' => 'new']);

        $this->conversationWith();

        $metrics = $this->metrics();

        $this->assertSame(0, $metrics['ratings']['total']);
        $this->assertSame(0, $metrics['leads_by_status']['total']);
    }

    // -----------------------------------------------------------------
    // Motion
    // -----------------------------------------------------------------

    /**
     * The animations are markup-level, so they are assertable: the count-up
     * needs its target value on the element, and the live dot belongs to the
     * one figure that describes this moment rather than the period.
     */
    public function test_the_summary_numbers_carry_their_count_up_targets(): void
    {
        $this->conversationWith();
        Lead::factory()->count(4)->for($this->agent)->create(['status' => 'new']);

        $html = $this->page()->getContent();

        $this->assertStringContainsString('data-countup="4"', $html);
        // The live dot, on the active-conversations tile only.
        $this->assertSame(2, substr_count($html, 'pulse-ring'), 'The pulse belongs to the live tile and the highlight card, nowhere else.');
    }

    /** A figure that is not a plain number has nothing to count toward. */
    public function test_a_dash_is_never_animated_as_a_number(): void
    {
        $this->conversationWith();

        $html = $this->page()->getContent();

        $this->assertStringNotContainsString('data-countup="—"', $html);
        $this->assertStringNotContainsString('data-countup=""', $html);
    }
}
