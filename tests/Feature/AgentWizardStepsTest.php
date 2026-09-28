<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\User;
use App\Services\AgentReplyService;
use App\Services\Llm\LlmProviderInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The agent wizard is four steps, with the two free-text instruction fields
 * living together on the new step 2.
 */
class AgentWizardStepsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    protected function wizard()
    {
        return $this->actingAs($this->user)->get(route('agents.create'));
    }

    /**
     * The wizard's SHAPE, asserted at the source.
     *
     * These used to read the rendered Alpine markup — `x-for="n in 5"`,
     * `x-data="{ step: 1 }"`, the exact `<textarea ... rows="8">` tag. The form
     * is Pages/Agents/Form.vue now and none of those strings exists, but three
     * properties they protected are still worth pinning and still cannot be
     * seen from a PHP request:
     *
     *   · five steps, not four (a step was added once and the stepper was not)
     *   · rules ABOVE persona — rules are the concrete "if X then Y" an owner
     *     already has in mind; asking for character first sends them writing
     *     prose they then have to redo
     *   · a validation error REOPENS the step it belongs to, because an error
     *     on a step nobody is looking at is an error nobody sees
     */
    public function test_the_wizard_has_five_steps(): void
    {
        $form = $this->formSource();

        $this->assertStringContainsString('const STEPS = 5;', $form);

        foreach (['step1_heading', 'step2_heading', 'step3_heading', 'step4_heading', 'step5_heading'] as $key) {
            $this->assertStringContainsString("agents.form.{$key}", $form);
        }
    }

    public function test_the_rules_field_sits_above_the_persona_field(): void
    {
        $form = $this->formSource();

        $this->assertLessThan(
            strpos($form, 'id="custom_system_prompt"'),
            strpos($form, 'id="rules_instructions"'),
            'Rules must be asked for before character.',
        );
    }

    public function test_a_validation_error_reopens_the_step_it_belongs_to(): void
    {
        $form = $this->formSource();

        // The map that makes it possible, and the watcher that uses it.
        $this->assertStringContainsString('const stepOfField = {', $form);
        $this->assertStringContainsString('() => form.errors', $form);
        $this->assertStringContainsString('step.value = stepOfField[first]', $form);

        // And every posted field is on the map — a field missing from it would
        // fail silently, leaving the owner on a step with no visible error.
        foreach ([
            'name', 'description', 'tone',
            'rules_instructions', 'custom_system_prompt',
            'greeting_message', 'fallback_message', 'on_not_found', 'escalation',
            'proactivity', 'max_length', 'use_emojis',
            'haggling_enabled', 'haggling_level', 'haggle_min_price_percent', 'haggling_instructions',
        ] as $field) {
            $this->assertMatchesRegularExpression(
                '/^\s+'.preg_quote($field, '/').': \d,$/m',
                $form,
                "{$field} has no step, so an error on it would reopen nothing.",
            );
        }
    }

    protected function promptFor(Agent $agent): string
    {
        $captured = '';

        $this->mock(LlmProviderInterface::class, function ($mock) use (&$captured) {
            $mock->shouldReceive('embed')->andReturn(['vector' => [1, 0, 0], 'input_tokens' => 3]);
            $mock->shouldReceive('generate')
                ->withArgs(function ($system, $contents, $options = []) use (&$captured) {
                    $captured = $system;

                    return true;
                })
                ->andReturn(['text' => 'ok', 'tool_calls' => [], 'input_tokens' => 1, 'output_tokens' => 1]);
        });

        $this->app->make(AgentReplyService::class)
            ->reply($agent->conversations()->create(['status' => 'ai']), 'Привет');

        return $captured;
    }

    /** The create and edit screens are one component, so one source to read. */
    protected function formSource(): string
    {
        return file_get_contents(resource_path('js/Pages/Agents/Form.vue'));
    }

    public function test_the_rules_reach_the_system_prompt_as_their_own_section(): void
    {
        $agent = Agent::factory()->for($this->user)->create([
            'custom_system_prompt' => 'Speak warmly and briefly.',
            'rules_instructions' => 'Никогда не называй закупочные цены.',
        ]);

        $prompt = $this->promptFor($agent);

        // Both are present, verbatim...
        $this->assertStringContainsString('Speak warmly and briefly.', $prompt);
        $this->assertStringContainsString('Никогда не называй закупочные цены.', $prompt);
        // ...and as two distinct sections, persona first.
        $this->assertStringContainsString('personality and style', $prompt);
        $this->assertStringContainsString('situational rules', $prompt);
        $this->assertLessThan(
            strpos($prompt, 'situational rules'),
            strpos($prompt, 'personality and style')
        );
    }

    /**
     * Both outrank the tone/language presets, which is the existing contract
     * for the persona field and must hold for the rules too.
     */
    public function test_both_sections_are_marked_as_outranking_the_presets(): void
    {
        $agent = Agent::factory()->for($this->user)->create([
            'custom_system_prompt' => 'Persona text.',
            'rules_instructions' => 'Rule text.',
        ]);

        $prompt = $this->promptFor($agent);

        $this->assertSame(2, substr_count($prompt, 'take priority over the default tone/formatting guidance'));
        // The presets still follow, so the model sees what it is overriding.
        $this->assertStringContainsString('Default tone', $prompt);
    }

    /** An empty rules field must leave no header behind. */
    public function test_an_empty_rules_field_omits_the_section_entirely(): void
    {
        $agent = Agent::factory()->for($this->user)->create([
            'custom_system_prompt' => 'Persona only.',
            'rules_instructions' => null,
        ]);

        $prompt = $this->promptFor($agent);

        $this->assertStringNotContainsString('situational rules', $prompt);
        $this->assertStringContainsString('Persona only.', $prompt);
    }

    public function test_rules_alone_work_without_a_persona(): void
    {
        $agent = Agent::factory()->for($this->user)->create([
            'custom_system_prompt' => null,
            'rules_instructions' => 'Только правила.',
        ]);

        $prompt = $this->promptFor($agent);

        $this->assertStringContainsString('situational rules', $prompt);
        $this->assertStringContainsString('Только правила.', $prompt);
        $this->assertStringNotContainsString('personality and style', $prompt);
    }

    /** The markup of the step-2 panel, from its heading to the step-3 panel. */
    protected function stepTwoMarkup(string $content): string
    {
        $start = strpos($content, 'x-show="step === 2"');
        $this->assertNotFalse($start, 'Step 2 is missing from the wizard.');

        $end = strpos($content, 'x-show="step === 3"', $start);
        $this->assertNotFalse($end, 'Step 3 should follow step 2.');

        return substr($content, $start, $end - $start);
    }
}
