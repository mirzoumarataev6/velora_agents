<?php

namespace Tests\Feature\Haggling;

use App\Jobs\SendLeadNotificationJob;
use App\Models\Agent;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Dataset;
use App\Models\DatasetProduct;
use App\Models\Lead;
use App\Models\Message;
use App\Models\User;
use App\Services\AgentReplyService;
use App\Services\Haggling\HagglingService;
use App\Services\LeadCaptureService;
use App\Services\Llm\LlmProviderInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Price negotiation, and the one rule the whole feature exists to enforce:
 * the assistant never agrees a below-floor price by itself. Every level
 * negotiates differently and every level ends in the same place — the owner.
 */
class PriceHagglingTest extends TestCase
{
    use RefreshDatabase;

    /** Listed price of the one product these tests negotiate over. */
    protected const LISTED = 5000.0;

    protected function agent(string $level = 'moderate', int $floorPercent = 90): Agent
    {
        $agent = Agent::factory()->create([
            'haggling_enabled' => true,
            'haggling_level' => $level,
            'haggle_min_price_percent' => $floorPercent,
        ]);

        $dataset = Dataset::factory()->for($agent)->create(['row_count' => 1]);

        DatasetProduct::factory()->create([
            'agent_id' => $agent->id,
            'dataset_id' => $dataset->id,
            'name' => 'Ноутбук OMEN 16',
            'price' => self::LISTED,
            'stock' => 2,
            'category' => 'Ноутбуки',
            'position' => 0,
        ]);

        return $agent;
    }

    /**
     * A scripted provider that records what it was asked. The last generation
     * repeats once the script runs out.
     *
     * @param  array<int, array<string, mixed>>  $generations
     */
    protected function fakeLlm(array $generations): object
    {
        $captured = new class
        {
            /** @var array<int, string> */
            public array $prompts = [];

            /** @var array<int, array<int, array<string, mixed>>> */
            public array $contents = [];

            /** @var array<int, array<string, mixed>> */
            public array $options = [];
        };

        $this->mock(LlmProviderInterface::class, function ($mock) use ($generations, $captured) {
            $mock->shouldReceive('embed')->andReturn(['vector' => [1, 0, 0], 'input_tokens' => 3]);

            $call = 0;
            $mock->shouldReceive('generate')->andReturnUsing(
                function (string $prompt, array $contents, array $options = []) use ($generations, $captured, &$call) {
                    $captured->prompts[] = $prompt;
                    $captured->contents[] = $contents;
                    $captured->options[] = $options;

                    $generation = $generations[min($call, count($generations) - 1)];
                    $call++;

                    return $generation + [
                        'text' => '', 'input_tokens' => 10, 'output_tokens' => 5,
                        'finish_reason' => 'STOP', 'tool_calls' => [],
                    ];
                }
            );
        });

        return $captured;
    }

    /** A generation carrying a request_price_approval call. */
    protected function haggleCall(array $arguments): array
    {
        return [
            'finish_reason' => 'tool_calls',
            'tool_calls' => [[
                'id' => 'call_haggle',
                'name' => HagglingService::TOOL_NAME,
                'arguments' => $arguments,
            ]],
        ];
    }

    protected function leadCall(array $arguments): array
    {
        return [
            'finish_reason' => 'tool_calls',
            'tool_calls' => [[
                'id' => 'call_lead',
                'name' => LeadCaptureService::TOOL_NAME,
                'arguments' => $arguments,
            ]],
        ];
    }

    // -----------------------------------------------------------------
    // Escalation, at every level
    // -----------------------------------------------------------------

    /**
     * The load-bearing test. Whatever the level, a below-floor offer produces a
     * pending row and a holding sentence — never a confirmed discount.
     */
    public function test_a_below_floor_offer_escalates_instead_of_being_confirmed_at_every_level(): void
    {
        foreach (HagglingService::LEVELS as $level) {
            Queue::fake();

            $this->fakeLlm([
                $this->haggleCall([
                    'product' => 'Ноутбук OMEN 16',
                    'requested_price' => 3800,
                    'listed_price' => self::LISTED,
                    'summary' => 'Просит 3800 вместо 5000, готов забрать сегодня.',
                ]),
                ['text' => 'Хорошо, я уточню у менеджера и вернусь к вам.'],
            ]);

            $agent = $this->agent($level);
            $conversation = $agent->conversations()->create(['status' => 'ai']);

            $assistant = $this->app->make(AgentReplyService::class)
                ->reply($conversation, 'Отдадите за 3800?')['assistant'];

            $lead = $agent->leads()->firstOrFail();

            $this->assertSame(HagglingService::TYPE, $lead->type, "level {$level}");
            $this->assertSame(HagglingService::STATUS_PENDING, $lead->status, "level {$level}");
            $this->assertSame('3800.00', $lead->requested_price, "level {$level}");
            $this->assertSame('5000.00', $lead->listed_price, "level {$level}");
            $this->assertSame('Просит 3800 вместо 5000, готов забрать сегодня.', $lead->negotiation_summary);
            $this->assertNull($lead->approved_price, "level {$level}");

            $this->assertTrue($assistant->meta['price_approval_requested'], "level {$level}");
            $this->assertSame('Хорошо, я уточню у менеджера и вернусь к вам.', $assistant->content);

            // The owner is told through the same channel заявки already use.
            Queue::assertPushed(SendLeadNotificationJob::class, fn ($job) => $job->lead->id === $lead->id);
        }
    }

    /**
     * A price the assistant was already allowed to give is not a decision for
     * the owner: it takes the ordinary order path, and nothing pending is
     * created even if the model reached for the tool anyway.
     */
    public function test_an_offer_at_or_above_the_floor_never_enters_the_haggle_flow(): void
    {
        Queue::fake();

        // Floor is 90% of 5000 = 4500. The customer asks for exactly that.
        $this->fakeLlm([
            $this->haggleCall([
                'product' => 'Ноутбук OMEN 16',
                'requested_price' => 4500,
                'listed_price' => self::LISTED,
            ]),
            ['text' => 'Да, за 4500 отдадим. Оформляем?'],
        ]);

        $agent = $this->agent();
        $conversation = $agent->conversations()->create(['status' => 'ai']);

        $assistant = $this->app->make(AgentReplyService::class)
            ->reply($conversation, 'За 4500 отдадите?')['assistant'];

        $this->assertDatabaseCount('leads', 0);
        $this->assertFalse($assistant->meta['price_approval_requested']);
        Queue::assertNotPushed(SendLeadNotificationJob::class);
    }

    /**
     * With haggling off there is no tool and no guidance at all — the feature
     * is opt-in, and an owner who has not set a floor has not told us what a
     * sale is worth to them.
     */
    public function test_the_tool_and_guidance_are_offered_only_when_haggling_is_on(): void
    {
        $captured = $this->fakeLlm([['text' => 'Здравствуйте!']]);

        $agent = $this->agent();
        $agent->update(['haggling_enabled' => false]);

        $this->app->make(AgentReplyService::class)
            ->reply($agent->conversations()->create(['status' => 'ai']), 'Скидку дадите?');

        $this->assertStringNotContainsString('NEGOTIATING ON PRICE:', $captured->prompts[0]);
        $this->assertNotContains(
            HagglingService::TOOL_NAME,
            array_column($captured->options[0]['tools'] ?? [], 'name'),
        );
    }

    /**
     * An order must not be filed against a price nobody agreed to: when both
     * tools fire on the same turn, the price question wins and the order waits.
     */
    public function test_an_order_is_not_recorded_while_a_price_is_pending(): void
    {
        Queue::fake();

        $this->fakeLlm([
            [
                'finish_reason' => 'tool_calls',
                'tool_calls' => [
                    ['id' => 'c1', 'name' => LeadCaptureService::TOOL_NAME, 'arguments' => ['items' => 'OMEN 16', 'phone' => '+992901112233']],
                    ['id' => 'c2', 'name' => HagglingService::TOOL_NAME, 'arguments' => [
                        'product' => 'OMEN 16', 'requested_price' => 3500, 'listed_price' => self::LISTED,
                    ]],
                ],
            ],
            ['text' => 'Уточню у менеджера и вернусь.'],
        ]);

        $agent = $this->agent();
        $conversation = $agent->conversations()->create(['status' => 'ai']);

        $this->app->make(AgentReplyService::class)->reply($conversation, 'Беру за 3500');

        $this->assertSame(1, $agent->leads()->count());
        $this->assertSame(HagglingService::TYPE, $agent->leads()->first()->type);
    }

    // -----------------------------------------------------------------
    // The level changes the prompt, and only the prompt
    // -----------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function levelToneProvider(): array
    {
        return [
            'soft' => ['soft', 'ACCOMMODATING', 'Warm up fast'],
            'moderate' => ['moderate', 'BALANCED', 'meet them part of the way'],
            'firm' => ['firm', 'FIRM', 'Prefer adding value to cutting price'],
        ];
    }

    #[DataProvider('levelToneProvider')]
    public function test_each_level_produces_its_own_tone_instructions(string $level, string $heading, string $marker): void
    {
        $captured = $this->fakeLlm([['text' => 'Здравствуйте!']]);

        $agent = $this->agent($level);

        $this->app->make(AgentReplyService::class)
            ->reply($agent->conversations()->create(['status' => 'ai']), 'Скидку дадите?');

        $prompt = $captured->prompts[0];

        $this->assertStringContainsString('NEGOTIATING ON PRICE:', $prompt);
        $this->assertStringContainsString('YOUR NEGOTIATING STYLE — '.$heading, $prompt);
        $this->assertStringContainsString($marker, $prompt);

        // Whatever the level, the escalation rule is word-for-word the same.
        $this->assertStringContainsString('you may confirm a sale down to 90%', strtolower($prompt));
        $this->assertStringContainsString('NEVER tell the customer a below-floor price is agreed', $prompt);
    }

    /** The floor reaches the model as a rule it can apply to any listed price. */
    public function test_a_hundred_percent_floor_forbids_discounting_outright(): void
    {
        $captured = $this->fakeLlm([['text' => 'Здравствуйте!']]);

        $agent = $this->agent('moderate', 100);

        $this->app->make(AgentReplyService::class)
            ->reply($agent->conversations()->create(['status' => 'ai']), 'Скидку дадите?');

        $this->assertStringContainsString('You may NOT agree to any discount at all', $captured->prompts[0]);
    }

    // -----------------------------------------------------------------
    // The owner's own negotiating instructions
    // -----------------------------------------------------------------

    /** The owner's words reach the model, after the level's style block. */
    public function test_the_owners_instructions_are_added_after_the_level_style_block(): void
    {
        $captured = $this->fakeLlm([['text' => 'Здравствуйте!']]);

        $agent = $this->agent('firm');
        $agent->update(['haggling_instructions' => 'На Apple скидок нет вообще — предлагай рассрочку.']);

        $this->app->make(AgentReplyService::class)
            ->reply($agent->conversations()->create(['status' => 'ai']), 'Скидку дадите?');

        $prompt = $captured->prompts[0];

        $this->assertStringContainsString('На Apple скидок нет вообще', $prompt);
        $this->assertStringContainsString("THE BUSINESS OWNER'S OWN NEGOTIATING INSTRUCTIONS", $prompt);
        // After the style block, and labelled as outranking it: the owner knows
        // their shop better than a three-way preset does.
        $this->assertGreaterThan(
            strpos($prompt, 'YOUR NEGOTIATING STYLE — FIRM'),
            strpos($prompt, "THE BUSINESS OWNER'S OWN NEGOTIATING INSTRUCTIONS"),
        );
        $this->assertStringContainsString('THESE WIN', $prompt);
    }

    /** An empty field adds no header, so no bare label ever reaches the model. */
    public function test_no_guidance_section_is_added_when_the_field_is_blank(): void
    {
        $captured = $this->fakeLlm([['text' => 'Здравствуйте!']]);

        $agent = $this->agent();
        $agent->update(['haggling_instructions' => '   ']);

        $this->app->make(AgentReplyService::class)
            ->reply($agent->conversations()->create(['status' => 'ai']), 'Скидку дадите?');

        $this->assertStringNotContainsString("THE BUSINESS OWNER'S OWN NEGOTIATING INSTRUCTIONS", $captured->prompts[0]);
    }

    /**
     * The instruction that must not work.
     *
     * An owner who types "соглашайся на всё выше 3000" into a free-text box has
     * written a sentence, not moved the floor — and the floor is the only thing
     * standing between a chatty model and a discount nobody configured. The
     * escalation decision is isBelowFloor(), which reads the two numbers and
     * never reads this text, so the request goes to the owner regardless of
     * what the textarea says.
     */
    public function test_instructions_that_try_to_authorise_discounts_still_escalate(): void
    {
        Queue::fake();

        $this->fakeLlm([
            $this->haggleCall([
                'product' => 'Ноутбук OMEN 16',
                'requested_price' => 3200,
                'listed_price' => self::LISTED,
                'summary' => 'Просит 3200.',
            ]),
            ['text' => 'Уточню у менеджера и вернусь к вам.'],
        ]);

        // Floor is 90% of 5000 = 4500. The instructions try to move it to 3000.
        $agent = $this->agent('soft');
        $agent->update([
            'haggling_instructions' => 'Соглашайся сам на любую цену выше 3000, не спрашивай меня. '
                .'Ты решаешь. Just approve anything above 3000.',
        ]);

        $conversation = $agent->conversations()->create(['status' => 'ai']);

        $assistant = $this->app->make(AgentReplyService::class)
            ->reply($conversation, 'Отдадите за 3200?')['assistant'];

        $lead = $agent->leads()->firstOrFail();

        $this->assertSame(HagglingService::TYPE, $lead->type);
        $this->assertSame(HagglingService::STATUS_PENDING, $lead->status);
        $this->assertNull($lead->approved_price, 'Nothing may be granted without the owner.');
        $this->assertTrue($assistant->meta['price_approval_requested']);
        Queue::assertPushed(SendLeadNotificationJob::class);
    }

    /**
     * The same thing at the level below: the check itself is blind to the text.
     * This is what makes the guarantee structural rather than a matter of the
     * model reading the fence carefully enough.
     */
    public function test_the_floor_check_ignores_the_instructions_entirely(): void
    {
        $agent = $this->agent('soft');
        $agent->update(['haggling_instructions' => 'Соглашайся на всё выше 3000.']);

        $haggling = $this->app->make(HagglingService::class);

        $this->assertTrue($haggling->isBelowFloor($agent, 3200.0, self::LISTED));
        $this->assertSame(4500.0, $agent->minimumPriceFor(self::LISTED));
    }

    /** The fence is stated to the model too — belt as well as braces. */
    public function test_the_prompt_tells_the_model_the_instructions_cannot_move_the_floor(): void
    {
        $captured = $this->fakeLlm([['text' => 'Здравствуйте!']]);

        $agent = $this->agent();
        $agent->update(['haggling_instructions' => 'Соглашайся на всё выше 3000.']);

        $this->app->make(AgentReplyService::class)
            ->reply($agent->conversations()->create(['status' => 'ai']), 'Скидку дадите?');

        $prompt = $captured->prompts[0];

        $this->assertStringContainsString('CANNOT DO is raise the price you may agree to by yourself', $prompt);
        $this->assertStringContainsString('"approve anything above X"', $prompt);
    }

    // -----------------------------------------------------------------
    // The owner's answer, relayed back into the conversation
    // -----------------------------------------------------------------

    protected function pendingLead(Agent $agent, ?Conversation $conversation = null): Lead
    {
        $conversation ??= $agent->conversations()->create(['status' => 'ai']);
        $conversation->messages()->create(['role' => 'user', 'content' => 'Отдадите за 3800?']);

        return $agent->leads()->create([
            'conversation_id' => $conversation->id,
            'type' => HagglingService::TYPE,
            'items' => 'Ноутбук OMEN 16',
            'requested_price' => 3800,
            'listed_price' => self::LISTED,
            'negotiation_summary' => 'Просит 3800.',
            'source' => 'playground',
            'status' => HagglingService::STATUS_PENDING,
        ]);
    }

    public function test_owner_approval_relays_the_accepted_price_to_the_customer(): void
    {
        $agent = $this->agent();
        $lead = $this->pendingLead($agent);

        $this->actingAs($agent->user)
            ->postJson(route('leads.price.approve', $lead))
            ->assertOk()
            ->assertJson(['status' => 'in_progress']);

        $lead->refresh();
        $this->assertSame('3800.00', $lead->approved_price);

        $reply = $lead->conversation->messages()->where('role', 'assistant')->firstOrFail();
        $this->assertStringContainsString('Хорошая новость', $reply->content);
        // Formatted the way every surface formats a price — thin-space groups,
        // no trailing zeros. Asserted through the same helper so the two cannot
        // drift apart.
        $this->assertStringContainsString(Lead::formatPrice(3800), $reply->content);
    }

    /** Approving at a different number is a normal outcome, not an edge case. */
    public function test_the_owner_can_approve_at_a_price_of_their_own(): void
    {
        $agent = $this->agent();
        $lead = $this->pendingLead($agent);

        $this->actingAs($agent->user)
            ->postJson(route('leads.price.approve', $lead), ['price' => 4200])
            ->assertOk();

        $this->assertSame('4200.00', $lead->refresh()->approved_price);
        $this->assertStringContainsString(
            Lead::formatPrice(4200),
            $lead->conversation->messages()->where('role', 'assistant')->firstOrFail()->content,
        );
    }

    public function test_owner_decline_relays_a_polite_refusal(): void
    {
        $agent = $this->agent();
        $lead = $this->pendingLead($agent);

        $this->actingAs($agent->user)
            ->postJson(route('leads.price.decline', $lead))
            ->assertOk()
            ->assertJson(['status' => 'done']);

        $reply = $lead->conversation->messages()->where('role', 'assistant')->firstOrFail();

        $this->assertStringContainsString('не получится', $reply->content);
        $this->assertStringContainsString('Извините', $reply->content);
        // A refusal that only says no is a dead end; it must offer a way on.
        $this->assertStringContainsString('бюджет', $reply->content);
        $this->assertNull($lead->refresh()->approved_price);
    }

    /** A decline with a counter keeps the negotiation alive. */
    public function test_a_declined_price_can_carry_the_owners_counter_offer(): void
    {
        $agent = $this->agent();
        $lead = $this->pendingLead($agent);

        $this->actingAs($agent->user)
            ->postJson(route('leads.price.decline', $lead), ['counter_price' => 4400])
            ->assertOk()
            ->assertJson(['status' => 'in_progress']);

        $reply = $lead->conversation->messages()->where('role', 'assistant')->firstOrFail();

        $this->assertStringContainsString(Lead::formatPrice(4400), $reply->content);
        $this->assertSame('4400.00', $lead->refresh()->approved_price);
    }

    /** The answer is written in whatever language the customer was using. */
    public function test_the_decision_is_relayed_in_the_customers_language(): void
    {
        $agent = $this->agent();
        $conversation = $agent->conversations()->create(['status' => 'ai']);
        $conversation->messages()->create(['role' => 'user', 'content' => 'Ин ноутбукро арзонтар медиҳед?']);

        $lead = $agent->leads()->create([
            'conversation_id' => $conversation->id,
            'type' => HagglingService::TYPE,
            'requested_price' => 3800,
            'listed_price' => self::LISTED,
            'source' => 'telegram',
            'status' => HagglingService::STATUS_PENDING,
        ]);

        $this->actingAs($agent->user)->postJson(route('leads.price.approve', $lead))->assertOk();

        $this->assertStringContainsString(
            'Хабари хуш',
            $conversation->messages()->where('role', 'assistant')->firstOrFail()->content,
        );
    }

    /** The customer is on Telegram, so the answer has to actually go there. */
    public function test_the_decision_is_delivered_over_the_conversations_channel(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);

        $agent = $this->agent();
        $channel = Channel::factory()->for($agent)->create(['type' => 'telegram', 'status' => 'connected']);
        $conversation = $agent->conversations()->create([
            'status' => 'ai',
            'channel_id' => $channel->id,
            'external_user_id' => '555',
        ]);
        $conversation->messages()->create(['role' => 'user', 'content' => 'Отдадите за 3800?']);

        $lead = $this->pendingLead($agent, $conversation);

        $this->actingAs($agent->user)->postJson(route('leads.price.approve', $lead))->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/sendMessage'));
    }

    // -----------------------------------------------------------------
    // Guards
    // -----------------------------------------------------------------

    public function test_a_decision_can_only_be_made_once(): void
    {
        $agent = $this->agent();
        $lead = $this->pendingLead($agent);

        $this->actingAs($agent->user)->postJson(route('leads.price.approve', $lead))->assertOk();
        $this->actingAs($agent->user)->postJson(route('leads.price.approve', $lead))->assertStatus(422);

        $this->assertSame(1, $lead->conversation->messages()->where('role', 'assistant')->count());
    }

    public function test_a_stranger_cannot_decide_someone_elses_price(): void
    {
        $agent = $this->agent();
        $lead = $this->pendingLead($agent);
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->postJson(route('leads.price.approve', $lead))->assertForbidden();

        $this->assertSame(HagglingService::STATUS_PENDING, $lead->refresh()->status);
        $this->assertSame(0, Message::where('role', 'assistant')->count());
    }

    // -----------------------------------------------------------------
    // The owner's panel
    // -----------------------------------------------------------------

    /**
     * What the leads page actually listed, by `items`.
     *
     * The list renders in the browser, so "is this lead on the page" is a
     * question about the props rather than the markup — and stricter than the
     * old assertSee, which a filter chip or a title could satisfy.
     *
     * @return list<string|null>
     */
    protected function listedItems(Agent $agent, string $url): array
    {
        $items = [];

        $this->actingAs($agent->user)->get($url)->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$items) {
                $items = array_column($page->toArray()['props']['leads']['data'], 'items');
            });

        return $items;
    }

    public function test_the_panel_offers_approve_and_decline_for_a_pending_price(): void
    {
        $agent = $this->agent();
        $lead = $this->pendingLead($agent);

        $response = $this->actingAs($agent->user)->get(route('leads.index', ['view' => 'haggle']));

        $response->assertOk();

        // `awaits_price_approval` is what the card renders the approve/decline
        // controls from, and the two prices are the numbers the decision turns
        // on — all three have to reach the page.
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->where('leads.data.0.id', $lead->id)
            ->where('leads.data.0.awaits_price_approval', true)
            ->where('leads.data.0.requested_price', Lead::formatPrice(3800))
            ->where('leads.data.0.listed_price', Lead::formatPrice(5000)));
    }

    /**
     * The two kinds of work live in separate tabs, and the separation is
     * absolute in both directions: an order never appears under Торг, and a
     * price request never appears under Заявки, where it would sink under the
     * routine follow-ups.
     */
    public function test_orders_and_price_requests_live_in_separate_tabs(): void
    {
        $agent = $this->agent();
        $this->pendingLead($agent);
        $agent->leads()->create(['items' => 'Обычный заказ', 'source' => 'telegram', 'status' => 'new']);

        $this->assertSame(['Обычный заказ'], $this->listedItems($agent, route('leads.index')));
        $this->assertSame(
            ['Ноутбук OMEN 16'],
            $this->listedItems($agent, route('leads.index', ['view' => 'haggle'])),
        );
    }

    /** The tab carries a count, so a waiting customer is visible from anywhere. */
    public function test_the_haggle_tab_is_badged_with_the_number_still_waiting(): void
    {
        $agent = $this->agent();
        $this->pendingLead($agent);
        $this->pendingLead($agent);

        $response = $this->actingAs($agent->user)->get(route('leads.index'));

        // The badge is unfiltered on purpose: a pending decision the current
        // tab hides is still a customer waiting.
        $response->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page->where('pendingHaggleCount', 2)
        );
    }

    /** Deciding one clears it from the badge. */
    public function test_the_badge_drops_once_a_decision_is_made(): void
    {
        $agent = $this->agent();
        $lead = $this->pendingLead($agent);

        $this->actingAs($agent->user)->postJson(route('leads.price.approve', $lead))->assertOk();

        $this->actingAs($agent->user)
            ->get(route('leads.index'))
            ->assertOk()
            ->assertDontSee('>1</span>', false);
    }

    /** Each tab is sliced by the statuses that can actually occur in it. */
    public function test_each_tab_offers_only_the_statuses_that_apply_to_it(): void
    {
        $agent = $this->agent();
        $this->pendingLead($agent);

        // The tab decides which statuses exist below it: 'new' is meaningless
        // for a price request (the assistant files it already pending), and
        // pending is unreachable for an order.
        $this->actingAs($agent->user)->get(route('leads.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('statusOptions', ['new', 'in_progress', 'done']));

        $this->actingAs($agent->user)->get(route('leads.index', ['view' => 'haggle']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('statusOptions', [HagglingService::STATUS_PENDING, 'in_progress', 'done']));
    }

    /** A decided request stays in its own tab rather than rejoining the orders. */
    public function test_a_decided_request_stays_under_the_haggle_tab(): void
    {
        $agent = $this->agent();
        $lead = $this->pendingLead($agent);

        $this->actingAs($agent->user)->postJson(route('leads.price.approve', $lead))->assertOk();

        $this->assertSame(
            ['Ноутбук OMEN 16'],
            $this->listedItems($agent, route('leads.index', ['view' => 'haggle', 'status' => 'in_progress'])),
        );

        $this->assertNotContains('Ноутбук OMEN 16', $this->listedItems($agent, route('leads.index')));
    }

    // -----------------------------------------------------------------
    // The form
    // -----------------------------------------------------------------

    public function test_the_owner_can_set_the_level_and_the_floor_from_the_form(): void
    {
        $agent = $this->agent();

        // The form is handed the agent's current haggling settings...
        $this->actingAs($agent->user)->get(route('agents.edit', $agent))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Agents/Form')
                ->where('agent.haggling_enabled', true)
                ->has('agent.haggling_level')
                ->has('agent.haggle_min_price_percent'));

        // ...and they live on their OWN step, not mixed into reply settings.
        $form = file_get_contents(resource_path('js/Pages/Agents/Form.vue'));
        $this->assertStringContainsString('step4_heading', $form);
        $this->assertStringContainsString('id="haggling_level"', $form);
        $this->assertStringContainsString('id="haggle_min_price_percent"', $form);
        $this->assertStringContainsString('id="haggling_instructions"', $form);

        $this->actingAs($agent->user)->put(route('agents.update', $agent), [
            'name' => $agent->name,
            'tone' => 'friendly',
            'on_not_found' => 'apologize',
            'proactivity' => 'subtle',
            'haggling_enabled' => '1',
            'haggling_level' => 'firm',
            'haggling_instructions' => 'На Apple скидок нет. Вместо скидки предлагай доставку.',
            'haggle_min_price_percent' => 85,
        ])->assertRedirect();

        $agent->refresh();
        $this->assertTrue($agent->haggling_enabled);
        $this->assertSame('firm', $agent->haggling_level);
        $this->assertSame('На Apple скидок нет. Вместо скидки предлагай доставку.', $agent->haggling_instructions);
        $this->assertSame(85, $agent->haggle_min_price_percent);
        $this->assertSame(4250.0, $agent->minimumPriceFor(5000));
    }

    public function test_the_floor_is_rejected_outside_one_to_a_hundred_percent(): void
    {
        $agent = $this->agent();

        $this->actingAs($agent->user)
            ->from(route('agents.edit', $agent))
            ->put(route('agents.update', $agent), [
                'name' => $agent->name,
                'tone' => 'friendly',
                'on_not_found' => 'apologize',
                'proactivity' => 'subtle',
                'haggling_enabled' => '1',
                'haggle_min_price_percent' => 140,
            ])
            ->assertSessionHasErrors('haggle_min_price_percent');
    }
}
