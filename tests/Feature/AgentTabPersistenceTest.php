<?php

namespace Tests\Feature;

use App\Http\Controllers\AgentController;
use App\Models\Agent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The open tab rides in `?tab=`, which is what lets it survive a reload and a
 * language switch (the locale switcher rebuilds the URL from
 * window.location.search, so the param goes along for the trip).
 */
class AgentTabPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_page_opens_on_the_tab_named_in_the_query(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();

        foreach (AgentController::TABS as $tab) {
            $this->actingAs($user)
                ->get(route('agents.show', ['agent' => $agent, 'tab' => $tab]))
                ->assertOk()
                ->assertSee("tab: '{$tab}'", false);
        }
    }

    public function test_it_falls_back_to_the_first_tab_when_none_is_named(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();

        $this->actingAs($user)
            ->get(route('agents.show', $agent))
            ->assertOk()
            ->assertSee("tab: 'knowledge'", false);
    }

    /**
     * Every panel is x-shown against this value, so an unrecognized tab would
     * render a page with no content at all — and it reaches the page from the
     * query string, where anyone can put anything.
     */
    public function test_an_unknown_tab_falls_back_instead_of_reaching_the_page(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();

        foreach (['nope', "'});alert(1);({tab:'x", '<script>'] as $tab) {
            $response = $this->actingAs($user)
                ->get(route('agents.show', ['agent' => $agent, 'tab' => $tab]));

            $response->assertOk()->assertSee("tab: 'knowledge'", false);
            $response->assertDontSee('alert(1)', false);
        }
    }

    public function test_switching_tabs_rewrites_the_query_so_a_reload_stays_put(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();

        $this->actingAs($user)
            ->get(route('agents.show', $agent))
            ->assertOk()
            ->assertSee("url.searchParams.set('tab', value)", false)
            ->assertSee('window.history.replaceState', false);
    }
}
