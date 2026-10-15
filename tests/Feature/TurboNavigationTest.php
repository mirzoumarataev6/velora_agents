<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression coverage for the "navigation looks like it refreshes twice"
 * report. Diagnosed with a Playwright probe (not part of this suite — it
 * drove a real browser and counted Turbo lifecycle events, see the task's
 * final report for the numbers): revisiting a page already in Turbo's
 * snapshot cache paints that stale snapshot immediately, then replaces it
 * with the fresh response — two `turbo:render` events, not one, ~250ms
 * apart. A never-cached-before navigation only rendered once.
 *
 * Not executable from PHPUnit (it's runtime JS behaviour), so this asserts
 * on what the server actually emits: the opt-out meta tag.
 */
class TurboNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_opts_out_of_turbos_stale_preview_render(): void
    {
        $user = User::factory()->create();
        Agent::factory()->for($user)->create();

        $html = $this->actingAs($user)->get('/agents')->assertOk()->getContent();

        $this->assertStringContainsString(
            '<meta name="turbo-cache-control" content="no-preview" />',
            $html
        );
    }
}
