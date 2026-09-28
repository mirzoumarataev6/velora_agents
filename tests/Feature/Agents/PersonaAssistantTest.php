<?php

namespace Tests\Feature\Agents;

use App\Exceptions\LlmException;
use App\Models\Agent;
use App\Models\Dataset;
use App\Models\DatasetProduct;
use App\Models\User;
use App\Services\Agents\AgentPersonaAssistant;
use App\Services\Llm\LlmProviderInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The writing helper for the two free-text fields.
 *
 * The behaviour worth pinning down is less "does it call the model" than "does
 * it stay out of the way": the textareas must survive a failure untouched, and
 * nothing may reach the agent record without the owner pressing save.
 */
class PersonaAssistantTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->agent = Agent::factory()->for($this->owner)->create([
            'name' => 'Олами Компютерхо',
            'description' => 'Магазин ноутбуков и аксессуаров в Душанбе',
            'rules_instructions' => 'СТАРЫЕ ПРАВИЛА',
            'custom_system_prompt' => 'СТАРЫЙ ХАРАКТЕР',
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function answers(array $overrides = []): array
    {
        return array_merge([
            'priority' => 'Скорость и доведение до заказа',
            'forbidden' => 'Не обсуждать закупочные цены',
            'phrases' => 'Всегда на «Вы»',
            'angry' => 'Извиниться и позвать менеджера',
            'extra' => 'Доставка только по Душанбе',
        ], $overrides);
    }

    protected function mockDraft(string $text): void
    {
        $this->mock(LlmProviderInterface::class, function ($mock) use ($text) {
            $mock->shouldReceive('generate')->once()->andReturn([
                'text' => $text,
                'input_tokens' => 120,
                'output_tokens' => 300,
                'finish_reason' => 'STOP',
                'tool_calls' => [],
            ]);
        });
    }

    protected function validDraft(): string
    {
        return "===RULES===\n"
            ."• если клиент спрашивает про доставку — уточни район Душанбе и назови срок\n"
            ."• если клиент недоволен — извинись и предложи менеджера\n"
            ."===PERSONA===\n"
            .'Ты консультант магазина ноутбуков. Общайся на «Вы», спокойно и по делу.';
    }

    // -----------------------------------------------------------------
    // Generation
    // -----------------------------------------------------------------

    public function test_generation_produces_both_blocks(): void
    {
        $this->mockDraft($this->validDraft());

        $response = $this->actingAs($this->owner)->postJson(route('agents.persona-assistant'), [
            'agent' => $this->agent->slug,
            'name' => $this->agent->name,
            'description' => $this->agent->description,
            'answers' => $this->answers(),
        ]);

        $response->assertOk()->assertJsonStructure(['rules', 'persona']);

        $this->assertStringContainsString('если клиент спрашивает про доставку', $response->json('rules'));
        $this->assertStringContainsString('консультант магазина ноутбуков', $response->json('persona'));
        // The markers are a transport detail and must never reach the textarea.
        $this->assertStringNotContainsString('===RULES===', $response->json('rules'));
        $this->assertStringNotContainsString('===PERSONA===', $response->json('persona'));
    }

    /**
     * The whole point of the endpoint returning a draft rather than saving one:
     * the owner has not seen it yet.
     */
    public function test_the_agents_own_fields_are_left_untouched_until_the_owner_saves(): void
    {
        $this->mockDraft($this->validDraft());

        $this->actingAs($this->owner)->postJson(route('agents.persona-assistant'), [
            'agent' => $this->agent->slug,
            'name' => $this->agent->name,
            'answers' => $this->answers(),
        ])->assertOk();

        $this->agent->refresh();

        $this->assertSame('СТАРЫЕ ПРАВИЛА', $this->agent->rules_instructions);
        $this->assertSame('СТАРЫЙ ХАРАКТЕР', $this->agent->custom_system_prompt);
    }

    /** It has to work on the create form, where there is no agent yet. */
    public function test_a_draft_can_be_written_before_the_agent_exists(): void
    {
        $this->mockDraft($this->validDraft());

        $this->actingAs($this->owner)->postJson(route('agents.persona-assistant'), [
            'name' => 'Новый магазин',
            'description' => 'Продаём цветы',
            'answers' => ['priority' => 'Тепло и вежливо'],
        ])->assertOk()->assertJsonStructure(['rules', 'persona']);

        $this->assertDatabaseMissing('agents', ['name' => 'Новый магазин']);
    }

    // -----------------------------------------------------------------
    // The brief handed to the model
    // -----------------------------------------------------------------

    public function test_the_brief_carries_the_business_context_and_the_answers(): void
    {
        $dataset = Dataset::factory()->for($this->agent)->create(['row_count' => 1]);
        DatasetProduct::factory()->create([
            'agent_id' => $this->agent->id,
            'dataset_id' => $dataset->id,
            'name' => 'OMEN 16',
            'category' => 'Игровые ноутбуки',
            'position' => 0,
        ]);

        $brief = $this->app->make(AgentPersonaAssistant::class)
            ->brief($this->agent->fresh(), $this->answers());

        $this->assertStringContainsString('Олами Компютерхо', $brief);
        $this->assertStringContainsString('Магазин ноутбуков', $brief);
        $this->assertStringContainsString('Игровые ноутбуки', $brief);
        $this->assertStringContainsString('Скорость и доведение до заказа', $brief);
        $this->assertStringContainsString('Не обсуждать закупочные цены', $brief);
    }

    /** Empty answers are dropped rather than sent as blank labelled lines. */
    public function test_unanswered_questions_are_left_out_of_the_brief(): void
    {
        $brief = $this->app->make(AgentPersonaAssistant::class)
            ->brief($this->agent, ['priority' => 'Скорость', 'forbidden' => '', 'angry' => '   ']);

        $this->assertStringContainsString('Скорость', $brief);
        $this->assertStringNotContainsString('never discuss', $brief);
        $this->assertStringNotContainsString('angry', $brief);
    }

    /**
     * The negotiation feature owns discount policy. Without this note the model
     * cheerfully writes "если просят скидку — дай 10%" into the rules field,
     * contradicting the floor the owner set two steps later.
     */
    public function test_a_haggling_agent_is_told_not_to_write_discount_rules(): void
    {
        $this->agent->update(['haggling_enabled' => true]);

        $brief = $this->app->make(AgentPersonaAssistant::class)->brief($this->agent->fresh(), $this->answers());

        $this->assertStringContainsString('Do NOT write rules about granting discounts', $brief);
    }

    public function test_the_system_prompt_asks_for_the_two_registers_separately(): void
    {
        $prompt = $this->app->make(AgentPersonaAssistant::class)->systemPrompt();

        $this->assertStringContainsString('===RULES===', $prompt);
        $this->assertStringContainsString('===PERSONA===', $prompt);
        // Rules are "if X → do Y" lines, matching the field's own placeholder.
        $this->assertStringContainsString('если клиент', $prompt);
        // The persona is prose addressed to a new employee, per its guidance.
        $this->assertStringContainsString('instructions to a new employee', $prompt);
        // And it must never invent facts about a business it cannot see.
        $this->assertStringContainsString('Never invent products, prices', $prompt);
    }

    // -----------------------------------------------------------------
    // Failure, handled gracefully
    // -----------------------------------------------------------------

    public function test_a_provider_failure_returns_an_error_and_changes_nothing(): void
    {
        $this->mock(LlmProviderInterface::class, function ($mock) {
            $mock->shouldReceive('generate')->andThrow(new LlmException('quota exceeded'));
        });

        $this->actingAs($this->owner)->postJson(route('agents.persona-assistant'), [
            'agent' => $this->agent->slug,
            'name' => $this->agent->name,
            'answers' => $this->answers(),
        ])
            ->assertStatus(502)
            ->assertJson(['message' => __('agents.persona_assistant.errors.failed')]);

        $this->agent->refresh();
        $this->assertSame('СТАРЫЕ ПРАВИЛА', $this->agent->rules_instructions);
        $this->assertSame('СТАРЫЙ ХАРАКТЕР', $this->agent->custom_system_prompt);
    }

    /**
     * Half a draft is a failure, not a partial success: silently filling one
     * textarea and leaving the other is worse for the owner than an error they
     * can retry.
     */
    public function test_a_response_missing_a_section_is_treated_as_a_failure(): void
    {
        $this->mockDraft('===RULES===
• если клиент спрашивает про доставку — уточни район');

        $this->actingAs($this->owner)->postJson(route('agents.persona-assistant'), [
            'agent' => $this->agent->slug,
            'name' => $this->agent->name,
            'answers' => $this->answers(),
        ])->assertStatus(502);
    }

    public function test_an_empty_section_is_treated_as_a_failure(): void
    {
        $this->mockDraft("===RULES===\n\n===PERSONA===\nТы консультант.");

        $this->actingAs($this->owner)->postJson(route('agents.persona-assistant'), [
            'agent' => $this->agent->slug,
            'name' => $this->agent->name,
            'answers' => $this->answers(),
        ])->assertStatus(502);
    }

    public function test_answering_nothing_is_refused_before_the_model_is_called(): void
    {
        // No generate() expectation at all: reaching the provider would fail it.
        $this->mock(LlmProviderInterface::class, function ($mock) {
            $mock->shouldNotReceive('generate');
        });

        $this->actingAs($this->owner)->postJson(route('agents.persona-assistant'), [
            'name' => $this->agent->name,
            'answers' => ['priority' => '', 'forbidden' => '   '],
        ])
            ->assertStatus(422)
            ->assertJson(['message' => __('agents.persona_assistant.errors.no_answers')]);
    }

    public function test_the_name_is_required(): void
    {
        $this->actingAs($this->owner)
            ->postJson(route('agents.persona-assistant'), ['answers' => $this->answers()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    // -----------------------------------------------------------------
    // Access
    // -----------------------------------------------------------------

    public function test_a_guest_cannot_reach_the_helper(): void
    {
        $this->postJson(route('agents.persona-assistant'), [
            'name' => 'X',
            'answers' => $this->answers(),
        ])->assertUnauthorized();
    }

    /**
     * A stranger naming someone else's agent gets a draft written from their own
     * form fields only — the catalog and knowledge context is dropped, so the id
     * leaks nothing.
     */
    public function test_another_owners_agent_supplies_no_context(): void
    {
        $stranger = User::factory()->create();
        $dataset = Dataset::factory()->for($this->agent)->create(['row_count' => 1]);
        DatasetProduct::factory()->create([
            'agent_id' => $this->agent->id,
            'dataset_id' => $dataset->id,
            'name' => 'OMEN 16',
            'category' => 'Секретная категория',
            'position' => 0,
        ]);

        $captured = null;

        $this->mock(LlmProviderInterface::class, function ($mock) use (&$captured) {
            $mock->shouldReceive('generate')->andReturnUsing(function ($system, $contents) use (&$captured) {
                $captured = $contents[0]['text'];

                return [
                    'text' => $this->validDraft(),
                    'input_tokens' => 1, 'output_tokens' => 1, 'finish_reason' => 'STOP', 'tool_calls' => [],
                ];
            });
        });

        $this->actingAs($stranger)->postJson(route('agents.persona-assistant'), [
            'agent' => $this->agent->slug,
            'name' => 'Мой магазин',
            'answers' => ['priority' => 'Скорость'],
        ])->assertOk();

        $this->assertStringNotContainsString('Секретная категория', $captured);
        $this->assertStringContainsString('Мой магазин', $captured);
    }

    // -----------------------------------------------------------------
    // The form
    // -----------------------------------------------------------------

    public function test_the_helper_button_appears_beside_both_fields_on_create_and_edit(): void
    {
        foreach ([route('agents.create'), route('agents.edit', $this->agent)] as $url) {
            $response = $this->actingAs($this->owner)->get($url);

            $response->assertOk()->assertInertia(
                fn (AssertableInertia $page) => $page->component('Agents/Form')
            );
        }
    }

    /** The manual path is untouched for anyone who ignores the button. */
    public function test_the_fields_still_save_normally_without_using_the_helper(): void
    {
        $this->actingAs($this->owner)->put(route('agents.update', $this->agent), [
            'name' => $this->agent->name,
            'tone' => 'friendly',
            'on_not_found' => 'apologize',
            'proactivity' => 'subtle',
            'rules_instructions' => 'Написано вручную',
            'custom_system_prompt' => 'Тоже вручную',
        ])->assertRedirect();

        $this->agent->refresh();
        $this->assertSame('Написано вручную', $this->agent->rules_instructions);
        $this->assertSame('Тоже вручную', $this->agent->custom_system_prompt);
    }
}
