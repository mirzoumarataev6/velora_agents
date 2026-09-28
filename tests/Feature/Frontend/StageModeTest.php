<?php

namespace Tests\Feature\Frontend;

use App\Models\Agent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Stage mode — the presentation setting.
 *
 * These pin the contract rather than the styling: the styling is a token swap in
 * tokens.css and is free to change, but four things about it are load-bearing
 * and would break silently.
 *
 * @see docs/DESIGN.md
 */
class StageModeTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string}> */
    public static function documentShells(): array
    {
        return [
            'landing' => ['/ru'],
            'auth' => ['/ru/login'],
        ];
    }

    /**
     * The attribute has to be applied BEFORE first paint, on every shell.
     *
     * Everything stage mode changes is layout-affecting — root font-size most of
     * all — so applying it from app.js after paint would reflow the whole page
     * in front of the audience, on the one setting whose entire purpose is being
     * used in front of an audience.
     *
     * All three shells, because a presenter who switches it on at the landing
     * and then signs in must not watch it switch itself off.
     */
    #[DataProvider('documentShells')]
    public function test_stage_mode_is_applied_before_first_paint(string $url): void
    {
        $html = $this->get($url)->assertOk()->getContent();

        $this->assertStringContainsString("localStorage.getItem('mode') === 'stage'", $html);
        $this->assertStringContainsString("setAttribute('data-mode', 'stage')", $html);
    }

    public function test_the_dashboard_shell_also_applies_it_before_first_paint(): void
    {
        $user = User::factory()->create();
        Agent::factory()->for($user)->create();

        $html = $this->actingAs($user)->get(route('agents.index'))->assertOk()->getContent();

        $this->assertStringContainsString("localStorage.getItem('mode') === 'stage'", $html);
    }

    /**
     * The toggle carries its state in aria-pressed and both of its labels, so the
     * state is never signalled by appearance alone — the same rule the rest of
     * the system follows for status colours.
     */
    public function test_the_toggle_exposes_its_state_and_both_labels(): void
    {
        $user = User::factory()->create();
        Agent::factory()->for($user)->create();

        // The Blade toggle, on a page that is still Blade. The Vue one is
        // Components/StageToggle.vue and carries the same contract.
        $html = $this->actingAs($user)->get(route('conversations.index'))->assertOk()->getContent();

        $this->assertStringContainsString('id="stage-toggle"', $html);
        $this->assertStringContainsString('aria-pressed="false"', $html);
        $this->assertStringContainsString(__('agents.nav.stage_on'), $html);
        $this->assertStringContainsString(__('agents.nav.stage_off'), $html);
    }

    /**
     * Stage mode is a property of the ROOM, not of the person, so it must never
     * reach the account: the same owner wants it on for the projector and off at
     * their desk twenty minutes later. The theme endpoint is the one that
     * persists per-account, and stage mode must not start riding along with it.
     */
    public function test_stage_mode_is_never_persisted_to_the_account(): void
    {
        $user = User::factory()->create();
        Agent::factory()->for($user)->create();

        $html = $this->actingAs($user)->get(route('conversations.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('mode-url', $html, 'Stage mode must not have a server endpoint.');

        $this->assertArrayNotHasKey(
            'mode',
            $user->fresh()->getAttributes(),
            'The users table must not carry a stage-mode column.'
        );
    }

    /**
     * The four things stage mode actually changes. If a future edit drops one of
     * these from tokens.css the mode still "works" — it just stops doing the job
     * it exists for, which is the kind of regression nobody notices until the
     * room is full.
     */
    public function test_the_stage_block_still_changes_what_it_is_for(): void
    {
        $tokens = file_get_contents(resource_path('css/tokens.css'));

        $stage = substr($tokens, strpos($tokens, ":root[data-mode='stage']"));

        $this->assertStringContainsString('font-size: 112.5%', $stage, 'Stage mode must raise the root font size.');
        $this->assertStringContainsString('--v-border-width: 2px', $stage, 'Stage mode must double border width.');
        $this->assertMatchesRegularExpression(
            '/--v-text-muted:\s*var\(--v-slate-700\)/',
            $stage,
            'Stage mode must darken muted text a full step.'
        );
        $this->assertMatchesRegularExpression(
            '/--v-shadow-1:\s*none/',
            $stage,
            'Stage mode must switch shadows off.'
        );
    }
}
