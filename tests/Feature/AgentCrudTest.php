<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\KnowledgeSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function validWizardPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Fashion Store',
            'description' => 'Clothing store',
            'language' => 'ru',
            'tone' => 'friendly',
            'greeting_message' => 'Здравствуйте!',
            'fallback_message' => 'Уточню и вернусь с ответом.',
            'max_length' => 500,
            'use_emojis' => '1',
            'custom_system_prompt' => 'Always ask for size.',
            'rules_instructions' => 'Если клиент спрашивает про доставку — уточни город.',
            'on_not_found' => 'suggest_alternatives',
            'proactivity' => 'subtle',
        ], $overrides);
    }

    public function test_owner_can_create_agent_through_the_wizard(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('agents.store'), $this->validWizardPayload());

        $agent = Agent::where('name', 'Fashion Store')->firstOrFail();
        $response->assertRedirect(route('agents.show', $agent));

        $this->assertSame($user->id, $agent->user_id);
        $this->assertSame('ru', $agent->language);
        $this->assertSame('friendly', $agent->tone);
        $this->assertSame('active', $agent->status);
        $this->assertNotEmpty($agent->slug);
        $this->assertSame([
            'greeting_message' => 'Здравствуйте!',
            'fallback_message' => 'Уточню и вернусь с ответом.',
            'max_length' => 500,
            'use_emojis' => true,
        ], $agent->response_format);
        // A wizard post that omits `escalation` gets the configured default
        // rather than silently switching frustration detection off.
        $this->assertSame([
            'on_not_found' => 'suggest_alternatives',
            'escalation' => config('agent.escalation.default_sensitivity'),
        ], $agent->behavior);
        $this->assertSame('Always ask for size.', $agent->custom_system_prompt);
        // The new step-2 field persists alongside the persona, not instead of it.
        $this->assertSame('Если клиент спрашивает про доставку — уточни город.', $agent->rules_instructions);
    }

    /**
     * Both step-2 fields are optional; leaving them blank must not block
     * creation or write empty strings where null is meant.
     */
    public function test_the_instruction_fields_are_optional(): void
    {
        $user = User::factory()->create();
        $payload = $this->validWizardPayload();
        unset($payload['custom_system_prompt'], $payload['rules_instructions']);

        $this->actingAs($user)->post(route('agents.store'), $payload)->assertRedirect();

        $agent = Agent::where('name', 'Fashion Store')->firstOrFail();
        $this->assertNull($agent->rules_instructions);
        $this->assertNull($agent->custom_system_prompt);
    }

    public function test_rules_instructions_are_length_validated(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('agents.store'), $this->validWizardPayload([
                'rules_instructions' => str_repeat('a', 5001),
            ]))
            ->assertSessionHasErrors('rules_instructions');
    }

    public function test_editing_an_agent_keeps_the_rules_field(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create(['rules_instructions' => 'Старое правило']);

        $this->actingAs($user)->put(route('agents.update', $agent), $this->validWizardPayload([
            'name' => $agent->name,
            'rules_instructions' => 'Новое правило',
        ]))->assertRedirect();

        $this->assertSame('Новое правило', $agent->refresh()->rules_instructions);
    }

    public function test_two_agents_with_the_same_name_get_distinct_slugs(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('agents.store'), $this->validWizardPayload(['name' => 'Twins']));
        $this->actingAs($user)->post(route('agents.store'), $this->validWizardPayload(['name' => 'Twins']));

        $slugs = Agent::where('name', 'Twins')->pluck('slug');
        $this->assertCount(2, $slugs);
        $this->assertNotSame($slugs[0], $slugs[1]);
    }

    public function test_creating_agent_without_a_name_fails_validation_and_preserves_input(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('agents.store'), $this->validWizardPayload(['name' => '']));

        $response->assertSessionHasErrors('name');
        $this->assertDatabaseMissing('agents', ['description' => 'Clothing store']);
    }

    public function test_owner_can_update_their_agent(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();

        $response = $this->actingAs($user)->put(route('agents.update', $agent), $this->validWizardPayload([
            'name' => 'Renamed Store',
            'max_length' => 300,
        ]));

        $response->assertRedirect(route('agents.show', ['agent' => $agent->fresh(), 'tab' => 'settings']));
        $agent->refresh();
        $this->assertSame('Renamed Store', $agent->name);
        $this->assertSame(300, $agent->response_format['max_length']);
    }

    public function test_owner_can_pause_and_reactivate_agent(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create(['status' => 'active']);

        $this->actingAs($user)
            ->patch(route('agents.status', $agent), ['status' => 'paused'])
            ->assertRedirect(route('agents.show', ['agent' => $agent->fresh(), 'tab' => 'settings']));
        $this->assertSame('paused', $agent->fresh()->status);

        $this->actingAs($user)->patch(route('agents.status', $agent), ['status' => 'active']);
        $this->assertSame('active', $agent->fresh()->status);
    }

    public function test_pausing_via_ajax_returns_json_status(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create(['status' => 'active']);

        $this->actingAs($user)
            ->patchJson(route('agents.status', $agent), ['status' => 'paused'])
            ->assertOk()
            ->assertJson(['status' => 'paused']);
    }

    public function test_stranger_cannot_change_status_of_someone_elses_agent(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $agent = Agent::factory()->for($owner)->create(['status' => 'active']);

        $this->actingAs($stranger)
            ->patch(route('agents.status', $agent), ['status' => 'paused'])
            ->assertForbidden();

        $this->assertSame('active', $agent->fresh()->status);
    }

    public function test_owner_can_delete_agent_and_its_knowledge_sources_cascade(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();
        $source = KnowledgeSource::factory()->for($agent)->create();

        $this->actingAs($user)
            ->delete(route('agents.destroy', $agent))
            ->assertRedirect(route('agents.index'));

        $this->assertModelMissing($agent);
        $this->assertModelMissing($source);
    }

    public function test_agents_index_shows_empty_state_for_new_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('agents.index'))
            ->assertOk()
            ->assertSee('Пока нет ни одного агента');
    }

    /**
     * The personality field moved to the dedicated instructions step (step 2),
     * where it sits under the situational rules field. It used to live on
     * step 1; this asserts the new home rather than the old one.
     */
    public function test_create_wizard_shows_personality_field_on_the_instructions_step(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('agents.create'));

        $response->assertOk()->assertSee('Характер и стиль агента');

        // It must render inside the step-2 panel, after step 2 begins and
        // before step 3 does.
        $html = $response->getContent();
        $step2Start = strpos($html, 'Шаг 2');
        $step3Start = strpos($html, 'Шаг 3');
        $fieldPos = strpos($html, 'id="custom_system_prompt"');

        $this->assertNotFalse($step2Start);
        $this->assertNotFalse($step3Start);
        $this->assertGreaterThan($step2Start, $fieldPos);
        $this->assertLessThan($step3Start, $fieldPos);
    }

    public function test_validation_error_on_custom_system_prompt_is_reported(): void
    {
        $user = User::factory()->create();
        $longPrompt = str_repeat('a', 6000); // exceeds the 5000 char max

        $response = $this->actingAs($user)->post(route('agents.store'), $this->validWizardPayload([
            'custom_system_prompt' => $longPrompt,
        ]));

        $response->assertSessionHasErrors('custom_system_prompt');
    }
}
