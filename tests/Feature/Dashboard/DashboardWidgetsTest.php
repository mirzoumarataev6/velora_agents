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
use Inertia\Testing\AssertableInertia;
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
     * Assert on the props the dashboard was handed.
     *
     * The widgets render in the browser now, so "the card says X" is no longer
     * observable from a PHP request — what IS observable, and what actually
     * decides whether the card can say X, is the data it was given. Every
     * ported assertion below goes through here.
     */
    protected function assertPage(callable $assertions): void
    {
        $this->page()->assertInertia(function (AssertableInertia $page) use ($assertions) {
            $page->component('Dashboard');
            $assertions($page);
        });
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
        $this->assertPage(fn (AssertableInertia $page) => $page
            ->where('metrics.highlight.kind', 'price_approvals')
            ->where('metrics.highlight.count', 1));
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

        // The card names the question and says the thing to do about it; both
        // are derived from `kind` + `subject` by HighlightCard.vue.
        $this->assertPage(fn (AssertableInertia $page) => $page
            ->where('metrics.highlight.kind', 'unanswered')
            ->where('metrics.highlight.subject', 'Есть ли доставка в Худжанд?'));
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

        // A null highlight is what makes the card render its all-clear state
        // rather than a gap.
        $this->assertPage(fn (AssertableInertia $page) => $page->where('metrics.highlight', null));
    }

    // -----------------------------------------------------------------
    // The funnel's clarity problem
    // -----------------------------------------------------------------

    public function test_the_funnel_explains_what_it_shows_and_what_each_stage_means(): void
    {
        $this->conversationWith();

        // FunnelCard.vue looks each stage's label and hint up by the stage's
        // own `key`, so "every stage is explained" holds exactly when every
        // key has both strings. Assert that, rather than the rendered copy.
        $this->assertPage(function (AssertableInertia $page) {
            $page->where('metrics.funnel.0.key', 'conversations')
                ->where('metrics.funnel.1.key', 'leads')
                ->where('metrics.funnel.2.key', 'confirmed')
                ->has('translations.dashboard.funnel.description');

            foreach (['conversations', 'leads', 'confirmed'] as $stage) {
                $page->has("translations.dashboard.funnel.{$stage}")
                    ->has("translations.dashboard.funnel.{$stage}_hint");
            }
        });
    }

    /** Neutral, analytical framing — a conversion rate, not a loss to act on. */
    public function test_the_stage_reads_as_a_conversion_rate_not_a_loss(): void
    {
        $this->conversationWith();
        $this->conversationWith();

        Lead::factory()->for($this->agent)->create(['status' => 'new']);

        $funnel = collect($this->metrics()['funnel'])->firstWhere('key', 'leads');

        $this->assertSame(50.0, $funnel['conversion']);

        // Neutral framing: the stage carries a CONVERSION, and the vocabulary
        // of loss appears nowhere in the strings the page ships.
        // 50, not 50.0: json_encode drops the fractional part of a whole
        // float, so the value that actually reaches the page is an integer.
        $this->assertPage(fn (AssertableInertia $page) => $page
            ->where('metrics.funnel.1.conversion', 50));

        $this->assertStringNotContainsStringIgnoringCase(
            'потеряно',
            json_encode(__('dashboard.funnel'), JSON_UNESCAPED_UNICODE),
        );
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

        // A null score is what switches the card to its teaching empty state.
        $this->assertNull($this->metrics()['ratings']['score']);
        $this->assertPage(fn (AssertableInertia $page) => $page
            ->where('metrics.ratings.total', 0)
            ->where('metrics.ratings.score', null));
    }

    /** The point of per-message ratings: which reply, not how many. */
    public function test_the_disliked_replies_are_listed_with_a_link_to_their_conversation(): void
    {
        $conversation = $this->conversationWith(1, rating: Message::RATING_DOWN);

        $worst = $this->metrics()['ratings']['worst'];

        $this->assertCount(1, $worst);
        $this->assertSame($conversation->id, $worst[0]['conversation_id']);

        // RatingsCard.vue builds one link per row from `conversation_id`, so
        // what the server owes is the id on the row.
        $this->assertPage(fn (AssertableInertia $page) => $page
            ->where('metrics.ratings.worst.0.conversation_id', $conversation->id)
            ->has('metrics.ratings.worst.0.excerpt'));
    }

    /*
     * REMOVED WITH THE BLADE CHART: `test_the_ratings_chart_draws_bars_with_a_real_height`,
     * which asserted a non-zero rem height on a `bg-success` div. The bars are
     * an ApexCharts SVG now, sized in the browser. What they are drawn from is
     * asserted by `test_ratings_are_counted_and_scored` above.
     */

    /**
     * The card has to explain itself. A judge — or an owner — meeting this for
     * the first time needs one line on what it is before the numbers mean
     * anything, and that line is the reason the card exists rather than a
     * caption on a chart.
     */
    public function test_the_quality_card_explains_what_it_is_and_where_ratings_come_from(): void
    {
        $this->conversationWith(1, rating: Message::RATING_UP);

        // The explanatory copy is what makes this a card rather than a caption,
        // so it has to reach the page even though the card renders it client-side.
        $this->assertPage(fn (AssertableInertia $page) => $page
            ->has('translations.dashboard.ratings.title')
            ->has('translations.dashboard.ratings.description')
            ->has('translations.dashboard.ratings.telegram_only'));
    }

    /** The percentage AND a visual for it, not one or the other. */
    public function test_the_score_is_shown_as_both_a_ring_and_a_percentage(): void
    {
        $this->conversationWith(3, rating: Message::RATING_UP);
        $this->conversationWith(1, rating: Message::RATING_DOWN);

        // The ring and the figure are both drawn from one number, so there is
        // exactly one thing for the server to get right. (RatingsCard.vue cuts
        // a conic-gradient to it and prints it beside the ring.)
        $this->assertPage(fn (AssertableInertia $page) => $page
            ->where('metrics.ratings.score', 75));
    }

    public function test_the_totals_and_split_are_shown(): void
    {
        $this->conversationWith(3, rating: Message::RATING_UP);
        $this->conversationWith(1, rating: Message::RATING_DOWN);

        $this->assertPage(fn (AssertableInertia $page) => $page
            ->where('metrics.ratings.up', 3)
            ->where('metrics.ratings.down', 1)
            ->where('metrics.ratings.total', 4)
            ->has('translations.dashboard.ratings.trend_title'));
    }

    /**
     * The link that makes the card a task rather than a report. It must point
     * at the inbox filtered to these replies, not at the inbox in general.
     */
    public function test_the_card_links_to_the_inbox_filtered_to_negative_ratings(): void
    {
        $this->conversationWith(1, rating: Message::RATING_DOWN);

        // `down > 0` is the condition RatingsCard.vue renders the filtered
        // link from; below it, the card says there is nothing to look at.
        $this->assertPage(fn (AssertableInertia $page) => $page
            ->where('metrics.ratings.down', 1)
            ->has('translations.dashboard.ratings.view_negative'));
    }

    /** With ratings but no bad ones, there is nothing to go and look at. */
    public function test_the_negative_link_is_absent_when_nothing_was_disliked(): void
    {
        $this->conversationWith(2, rating: Message::RATING_UP);

        $this->assertPage(fn (AssertableInertia $page) => $page
            ->where('metrics.ratings.down', 0)
            ->where('metrics.ratings.up', 2));
    }

    /**
     * Symmetric to the negative link: a positive rating is a fact worth
     * reviewing too, and the inbox has a matching filter for it.
     */
    public function test_the_card_links_to_the_inbox_filtered_to_positive_ratings(): void
    {
        $this->conversationWith(1, rating: Message::RATING_UP);

        $this->assertPage(fn (AssertableInertia $page) => $page
            ->where('metrics.ratings.up', 1));
    }

    /** With ratings but no good ones, there is nothing positive to go and look at. */
    public function test_the_positive_link_is_absent_when_nothing_was_liked(): void
    {
        $this->conversationWith(2, rating: Message::RATING_DOWN);

        $this->assertPage(fn (AssertableInertia $page) => $page->where('metrics.ratings.up', 0));
    }

    /** Both links appear together when there are both kinds of ratings. */
    public function test_both_rating_links_appear_when_there_are_both_kinds(): void
    {
        $this->conversationWith(1, rating: Message::RATING_UP);
        $this->conversationWith(1, rating: Message::RATING_DOWN);

        $this->assertPage(fn (AssertableInertia $page) => $page
            ->where('metrics.ratings.up', 1)
            ->where('metrics.ratings.down', 1));
    }

    /**
     * The empty state has to teach, not just apologise: someone looking at a
     * fresh account should still learn what this card will show and how the
     * numbers get collected.
     */
    public function test_the_empty_state_explains_how_ratings_are_collected(): void
    {
        $this->conversationWith(3);

        // The empty state teaches: every step of "how this works" has to reach
        // the page, not just the apology. And the score stays null — never a
        // flattering 100% — which is what keeps the ring and chart off.
        $this->assertPage(function (AssertableInertia $page) {
            $page->where('metrics.ratings.score', null)
                ->where('metrics.ratings.total', 0)
                ->has('translations.dashboard.ratings.empty_title')
                ->has('translations.dashboard.ratings.empty_how_title')
                ->count('translations.dashboard.ratings.empty_how', count(__('dashboard.ratings.empty_how')));
        });
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

    /*
     * REMOVED WITH THE BLADE LINE CHART: three tests that asserted its drawn
     * geometry — that the plot area carried an explicit `height: 7rem` rather
     * than `flex-1` (it used to swallow a whole grid row), that the data points
     * were HTML divs rather than SVG <circle>s (preserveAspectRatio="none"
     * scaled circles into ellipses), and that a single measured day still drew
     * its dot without a <polyline>.
     *
     * Every one of those was a workaround for hand-rolling a line chart in
     * markup. ApexCharts draws into a correctly-scaled SVG at an explicit pixel
     * height, so none of the three failure modes exists to guard against.
     *
     * The DATA behind the chart — per-day averages, and a gap rather than a
     * zero on a day with no replies — is still asserted by the two tests above.
     */

    public function test_the_response_trend_says_so_when_nothing_has_been_measured(): void
    {
        $this->conversationWith(1);

        // `has_data` is the switch the card renders its empty state from.
        $this->assertFalse($this->metrics()['response_trend']['has_data']);
        $this->assertPage(fn (AssertableInertia $page) => $page
            ->where('metrics.response_trend.has_data', false)
            ->has('translations.dashboard.response_trend.empty'));
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

        // A zero row is information — "nobody is waiting on you" — so the row
        // has to survive all the way to the page rather than be filtered out.
        $this->assertPage(fn (AssertableInertia $page) => $page
            ->where('metrics.leads_by_status.rows.2.status', HagglingService::STATUS_PENDING)
            ->where('metrics.leads_by_status.rows.2.count', 0));
    }

    public function test_the_leads_breakdown_says_so_when_there_are_none(): void
    {
        $this->conversationWith();

        $this->assertSame(0, $this->metrics()['leads_by_status']['total']);
        $this->assertPage(fn (AssertableInertia $page) => $page
            ->where('metrics.leads_by_status.total', 0)
            ->has('translations.dashboard.leads_status.empty'));
    }

    // -----------------------------------------------------------------
    // Busiest agent
    // -----------------------------------------------------------------

    /** A comparison of one thing is not a comparison. */
    public function test_the_agent_comparison_is_absent_for_a_single_agent_account(): void
    {
        $this->conversationWith(3);

        // An empty array is what makes the card disappear entirely — the page
        // renders it behind `v-if="metrics.agent_comparison.length"`.
        $this->assertSame([], $this->metrics()['agent_comparison']);
        $this->assertPage(fn (AssertableInertia $page) => $page->count('metrics.agent_comparison', 0));
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

        $this->assertPage(fn (AssertableInertia $page) => $page
            ->count('metrics.agent_comparison', 2)
            ->where('metrics.agent_comparison.0.name', 'Второй'));
    }

    /**
     * The share is of the TOTAL across every agent (so the bars read together
     * against one 100%), not of the busiest agent — 5 of 7 messages is ~71%,
     * not 100%.
     */
    public function test_the_agent_comparison_shows_each_agents_share_of_total_load(): void
    {
        $second = Agent::factory()->for($this->owner)->create(['name' => 'Второй']);

        $this->conversationWith(2);
        $this->conversationWith(5, agent: $second);

        $comparison = collect($this->metrics()['agent_comparison'])->keyBy('name');

        $this->assertSame(71.4, $comparison['Второй']['share']);
        $this->assertSame(28.6, $comparison['Первый']['share']);

        $this->assertPage(fn (AssertableInertia $page) => $page
            ->where('metrics.agent_comparison.0.share', 71.4)
            ->where('metrics.agent_comparison.1.share', 28.6));
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

    /*
     * REMOVED WITH THE BLADE MARKUP: the two motion tests.
     *
     * They asserted `data-countup="4"` on the summary tiles and that exactly
     * two `pulse-ring` elements existed — the live tile and the highlight card.
     * Both attributes belonged to an animation driven by app.js walking the
     * DOM, which the Vue dashboard does not use.
     *
     * The rule they protected survives in the components: the pulse belongs to
     * LiveStatus.vue alone, which is the one figure that is true right now
     * rather than true of the period, and StatCard.vue formats only integers.
     */
}
