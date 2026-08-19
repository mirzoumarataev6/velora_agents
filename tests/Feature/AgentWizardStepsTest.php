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

    public function test_the_stepper_has_four_segments(): void
    {
        $response = $this->wizard();

        $response->assertOk();
        $response->assertSee('x-for="n in 4"', false);
        $response->assertDontSee('x-for="n in 3"', false);
    }

    public function test_all_four_step_headings_are_present_and_numbered(): void
    {
        $response = $this->wizard();

        $response->assertOk();
        foreach (['step1_heading', 'step2_heading', 'step3_heading', 'step4_heading'] as $key) {
            $response->assertSee(__("agents.form.{$key}"), false);
        }
    }

    /**
     * Step 1 keeps the business basics and loses the persona field, which now
     * lives on step 2 beside the new rules field.
     */
    public function test_step_one_keeps_the_basics_without_the_persona_field(): void
    {
        $response = $this->wizard();
        $content = $response->getContent();

        $response->assertOk();
        foreach (['name', 'description', 'language', 'tone'] as $field) {
            $this->assertStringContainsString('id="'.$field.'"', $content);
        }

        // Both textareas exist exactly once, and both sit inside step 2.
        $this->assertSame(1, substr_count($content, 'id="custom_system_prompt"'));
        $this->assertSame(1, substr_count($content, 'id="rules_instructions"'));

        $stepTwo = $this->stepTwoMarkup($content);
        $this->assertStringContainsString('id="rules_instructions"', $stepTwo);
        $this->assertStringContainsString('id="custom_system_prompt"', $stepTwo);
    }

    /** Rules first, persona below it — the order the brief specifies. */
    public function test_the_rules_field_sits_above_the_persona_field(): void
    {
        $content = $this->wizard()->getContent();

        $this->assertLessThan(
            strpos($content, 'id="custom_system_prompt"'),
            strpos($content, 'id="rules_instructions"')
        );
    }

    public function test_the_rules_field_is_a_large_optional_textarea(): void
    {
        $response = $this->wizard();

        $response->assertOk();
        $response->assertSee('<textarea id="rules_instructions" name="rules_instructions" rows="8"', false);
        // Optional: no required attribute on either instruction field.
        $this->assertStringNotContainsString(
            'id="rules_instructions" name="rules_instructions" rows="8" required',
            $response->getContent()
        );
        $response->assertSee(__('agents.form.rules_label'), false);
        $response->assertSee(__('agents.form.rules_help'), false);
    }

    /**
     * A validation bounce must reopen the step the bad field is on, or the
     * owner sees an untouched form with an error they cannot find.
     */
    public function test_a_validation_error_reopens_the_step_it_belongs_to(): void
    {
        $base = ['name' => 'Ok', 'language' => 'ru', 'tone' => 'friendly', 'on_not_found' => 'apologize', 'proactivity' => 'subtle'];

        // from() sets the referer, so the validation redirect goes back to the
        // wizard rather than to "/" — which is what back() would otherwise pick.
        $this->actingAs($this->user)
            ->from(route('agents.create'))
            ->followingRedirects()
            ->post(route('agents.store'), ['name' => ''] + $base)
            ->assertSee('x-data="{ step: 1 }"', false);

        // `rules_instructions` is on step 2, so its error must open step 2.
        $this->actingAs($this->user)
            ->from(route('agents.create'))
            ->followingRedirects()
            ->post(route('agents.store'), ['rules_instructions' => str_repeat('a', 5001)] + $base)
            ->assertSee('x-data="{ step: 2 }"', false);

        // And a step-3 field opens step 3.
        $this->actingAs($this->user)
            ->from(route('agents.create'))
            ->followingRedirects()
            ->post(route('agents.store'), ['max_length' => 999999] + $base)
            ->assertSee('x-data="{ step: 3 }"', false);
    }

    // ---------------------------------------------------------------
    // System prompt wiring
    // ---------------------------------------------------------------

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
