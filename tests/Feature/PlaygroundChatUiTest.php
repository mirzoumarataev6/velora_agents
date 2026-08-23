<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The Playground's chat surface. The behaviour itself is Alpine, so these pin
 * the wiring that decides whether it can work at all: which bubble renders
 * markdown, that the composer is reachable for focus, and that the arrival
 * animation is the class-based one the reduced-motion block can switch off.
 */
class PlaygroundChatUiTest extends TestCase
{
    use RefreshDatabase;

    protected function playground(User $user, Agent $agent): TestResponse
    {
        return $this->actingAs($user)->get(route('agents.show', ['agent' => $agent, 'tab' => 'playground']));
    }

    /**
     * Only the agent's side is markdown. What the owner typed is shown verbatim
     * through x-text — rendering their input as markup would be the one place a
     * user could inject into their own page.
     */
    public function test_only_the_assistant_bubble_renders_markdown(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();

        $response = $this->playground($user, $agent);

        $response->assertOk()
            ->assertSee('x-html="renderMarkdown(msg.content)"', false)
            ->assertSee('x-if="msg.role === \'user\'"', false)
            ->assertSee('x-text="msg.content"', false);
    }

    /**
     * The escape-before-render ordering is the whole safety argument for the
     * renderer, and it is the kind of thing a later "tidy-up" quietly reverses.
     */
    public function test_the_markdown_renderer_escapes_before_it_adds_markup(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();

        $response = $this->playground($user, $agent);
        $response->assertOk();

        $renderer = $this->rendererSource($response->getContent());

        $escapePosition = strpos($renderer, "replace(/[&<>\"']/g");
        $boldPosition = strpos($renderer, '<strong>$1</strong>');

        $this->assertNotFalse($escapePosition, 'the renderer no longer escapes HTML');
        $this->assertNotFalse($boldPosition, 'the renderer no longer renders bold');
        $this->assertLessThan($boldPosition, $escapePosition, 'markup is added before escaping — that is an XSS hole');

        // Links stay unrendered on purpose: an anchor built from model output is
        // a phishing vector, and a raw URL reads perfectly well as text.
        $this->assertStringNotContainsString('<a ', $renderer);
    }

    /**
     * Just the renderer function's body — asserting against the whole page would
     * match the dashboard's own markup (every nav link is an anchor).
     */
    protected function rendererSource(string $html): string
    {
        $start = strpos($html, 'function renderPlaygroundMarkdown');
        $this->assertNotFalse($start, 'the playground markdown renderer is not on the page');

        $end = strpos($html, 'function playgroundTab', $start);
        $this->assertNotFalse($end, 'could not find the end of the renderer');

        return substr($html, $start, $end - $start);
    }

    public function test_the_typing_indicator_replaces_the_old_spinner(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();

        $response = $this->playground($user, $agent);

        $response->assertOk()
            ->assertSee('pg-typing-dot', false)
            // Announced to screen readers, which see no animation at all.
            ->assertSee('aria-label="'.__('agents.playground.typing').'"', false);
    }

    /**
     * Class-based, not x-transition: only a class can be switched off by the
     * prefers-reduced-motion block in app.css.
     */
    public function test_arriving_messages_animate_through_a_reduced_motion_aware_class(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();

        $this->playground($user, $agent)
            ->assertOk()
            ->assertSee("msg._fresh && 'pg-msg-in'", false);
    }

    public function test_the_composer_is_focused_when_the_playground_tab_is_open(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();

        $this->playground($user, $agent)
            ->assertOk()
            ->assertSee('x-ref="messageInput"', false)
            ->assertSee("x-effect=\"if (tab === 'playground') focusComposer()\"", false);
    }
}
