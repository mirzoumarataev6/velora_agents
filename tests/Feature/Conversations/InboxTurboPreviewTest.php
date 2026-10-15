<?php

namespace Tests\Feature\Conversations;

use App\Models\Agent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression coverage for the repeated "Alpine Expression Error: m is not
 * defined" / "chunk is not defined" / "style is not defined" console errors
 * reported on the inbox (conversations/partials/inbox.blade.php).
 *
 * Diagnosed with a Playwright probe (not part of this suite — real browser,
 * real console capture) against four scenarios: sitting through polls,
 * navigating away and back with Turbo, switching conversations, switching
 * language. Only one reproduced it: revisiting /conversations after it is
 * already in Turbo's snapshot cache. Turbo's preview mounts a throwaway
 * Alpine instance for the stale cached snapshot (still showing whatever
 * thread/messages were open when it was cached) and tears it down ~250ms
 * later when the live response arrives. That instance's own init() already
 * kicked off real fetches (reload(), and open() for a deep-linked thread);
 * when those resolve AFTER teardown, their .then() callbacks assign into a
 * reactive object whose DOM scope Alpine has already discarded — exactly
 * "effects running after the x-for item scope no longer exists" for every
 * `c in conversations`, `m in messages` and `chunk in m.chunks` item alive
 * in that stale snapshot.
 *
 * Proof, by commit (5 revisit cycles of /conversations with a thread open,
 * counting unique "X is not defined" Alpine errors):
 *
 *   96c92c4 (pre-existing, codex/stage-d2)  — 598 (c:190 m:360 chunk:40 style:8)
 *   31fab6d (pre-existing, origin/main)     — 598 (c:190 m:360 chunk:40 style:8)
 *   ba79398 (this branch's parent tip)      —   0
 *
 * So this was never introduced by this branch's own work — it predates all
 * three prior tasks on it — and it is ALREADY FIXED here, as a side effect
 * of the turbo-cache-control: no-preview meta tag added in ce85164 for an
 * unrelated symptom (the dashboard's double-render). That tag removes the
 * stale-snapshot mount entirely, so there is no second Alpine instance left
 * to leak a fetch past its own teardown.
 *
 * There is therefore no new production fix in this commit — only coverage
 * that pins the existing one specifically to this page, since
 * TurboNavigationTest only ever asserted it on /agents. What this test
 * CANNOT prove (PHPUnit renders no JS, runs no browser) is the absence of
 * the console errors themselves; it proves the one precondition that was
 * shown, empirically, to eliminate them.
 */
class InboxTurboPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_conversations_inbox_opts_out_of_turbos_stale_preview_render(): void
    {
        $user = User::factory()->create();
        Agent::factory()->for($user)->create();

        $html = $this->actingAs($user)->get('/conversations')->assertOk()->getContent();

        $this->assertStringContainsString(
            '<meta name="turbo-cache-control" content="no-preview" />',
            $html
        );
    }

    /**
     * Same page, reached through an agent's own tab (conversations-tab.blade.php,
     * mounted with x-if) rather than the standalone /conversations route —
     * both render through layouts.dashboard, so both must carry the tag.
     */
    public function test_agent_scoped_inbox_tab_also_opts_out_via_the_shared_dashboard_layout(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();

        $html = $this->actingAs($user)->get("/agents/{$agent->slug}")->assertOk()->getContent();

        $this->assertStringContainsString(
            '<meta name="turbo-cache-control" content="no-preview" />',
            $html
        );
    }
}
