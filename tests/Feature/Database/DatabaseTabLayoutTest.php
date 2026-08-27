<?php

namespace Tests\Feature\Database;

use App\Http\Controllers\DatabaseConnectionController;
use App\Models\Agent;
use App\Models\DatabaseConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The database tab shows ONE step at a time.
 *
 * The tab is Alpine, so what a server-side test can hold is the contract
 * underneath the interactivity: the step is derived from the connection payload
 * rather than stored, every surface is gated on that derivation, and the state
 * the page is seeded with is the state the owner is actually in. That is enough
 * to catch the regression that matters — a section losing its gate and going
 * back to being permanently visible.
 */
class DatabaseTabLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected function page(?Agent $agent = null): TestResponse
    {
        $agent ??= Agent::factory()->for(User::factory())->create();

        return $this->actingAs($agent->user)
            ->get(route('agents.show', ['agent' => $agent, 'tab' => 'database']))
            ->assertOk();
    }

    public function test_the_step_is_derived_from_the_connection_rather_than_stored(): void
    {
        // Two facts the server already knows decide the whole layout. A third
        // copy of them in the browser would be one more thing able to disagree.
        $this->page()
            ->assertSee("if (!this.connection) return 'connect';", false)
            ->assertSee("return this.connection.usable ? 'done' : 'review';", false);
    }

    public function test_every_surface_after_the_form_is_gated_on_having_a_connection(): void
    {
        $page = $this->page();

        // Discovery, the status card and the advanced panel are all meaningless
        // before a server has answered, and none of them may render unguarded.
        $page->assertSee('x-show="connection && !editingCredentials && columns.length === 0 && (step === \'review\'', false);
        $page->assertSee('x-show="step === \'done\' && !editingCredentials', false);
        $page->assertSee('x-show="connection && !editingCredentials" x-cloak class="group"', false);
    }

    public function test_an_agent_with_no_connection_is_seeded_into_the_connect_step(): void
    {
        $agent = Agent::factory()->for(User::factory())->create();

        // The seed is asserted at its source rather than through the x-data
        // attribute: Js::from hex-escapes the payload for the attribute, and a
        // test that pins that encoding tests Laravel rather than this tab.
        $this->assertNull(DatabaseConnectionController::state($agent)['connection']);

        // Which leaves the credentials form as the only satisfied gate.
        $this->page($agent)->assertSee('x-show="!connection || editingCredentials"', false);
    }

    public function test_a_configured_agent_is_seeded_as_usable_so_it_lands_on_the_status_card(): void
    {
        $agent = Agent::factory()->for(User::factory())->create();
        DatabaseConnection::factory()->for($agent)->joined()->create();

        $state = DatabaseConnectionController::state($agent->refresh())['connection'];

        // `usable` is what flips the tab to its resting state, and the joins
        // ride along with it so the card can say where each field comes from
        // without re-scanning on every paint.
        $this->assertTrue($state['usable']);
        $this->assertSame('products', $state['table']);
        $this->assertSame('categories', $state['joins'][0]['table']);
        $this->assertSame(['category'], $state['joins'][0]['fields']);

        $this->page($agent)->assertOk();
    }

    public function test_the_long_copy_and_the_read_only_notice_are_behind_the_help_toggle(): void
    {
        $page = $this->page();

        // Both are still on the page — they are worth reading once — and neither
        // is on the first screen.
        $page->assertSee(__('agents.database.intro_tagline'));
        $page->assertSee('x-show="showHelp"', false);
        $page->assertSee(__('agents.database.readonly_notice'));
    }

    public function test_the_three_steps_are_named_in_the_owners_language(): void
    {
        $page = $this->page();

        foreach (['connect', 'review', 'done'] as $step) {
            $page->assertSee(__("agents.database.steps.{$step}"));
        }
    }

    public function test_only_the_leading_candidates_preview_is_asserted_as_products(): void
    {
        $page = $this->page();

        // The two preview headers: assertive for the table we are proposing,
        // neutral for the alternatives an owner is still judging.
        $page->assertSee('headerFor(field, true)', false);
        $page->assertSee('headerFor(field, false)', false);
    }
}
