<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Search on the Заявки page. The three searchable columns are the three things
 * an owner actually remembers a lead by: who they are, how to reach them, and
 * what they asked for.
 */
class LeadSearchTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->agent = Agent::factory()->for($this->owner)->create(['name' => 'Shop bot']);
    }

    public function test_search_matches_the_customer_name(): void
    {
        Lead::factory()->for($this->agent)->create(['customer_name' => 'Мадина Рахимова']);
        Lead::factory()->for($this->agent)->create(['customer_name' => 'Анвар Каримов']);

        $response = $this->actingAs($this->owner)->get(route('leads.index', ['q' => 'Мадина']));

        $response->assertOk();
        $response->assertSee('Мадина Рахимова');
        $response->assertDontSee('Анвар Каримов');
    }

    public function test_search_matches_the_contact(): void
    {
        Lead::factory()->for($this->agent)->create(['customer_name' => 'Findable', 'contact' => '+992900112233']);
        Lead::factory()->for($this->agent)->create(['customer_name' => 'Hidden', 'contact' => '+992555000111']);

        $response = $this->actingAs($this->owner)->get(route('leads.index', ['q' => '900112233']));

        $response->assertOk();
        $response->assertSee('Findable');
        $response->assertDontSee('Hidden');
    }

    public function test_search_matches_what_the_customer_wanted(): void
    {
        Lead::factory()->for($this->agent)->create(['customer_name' => 'Wants a dress', 'items' => 'Платье синее, 42']);
        Lead::factory()->for($this->agent)->create(['customer_name' => 'Wants shoes', 'items' => 'Туфли, 38']);

        $response = $this->actingAs($this->owner)->get(route('leads.index', ['q' => 'Платье']));

        $response->assertOk();
        $response->assertSee('Wants a dress');
        $response->assertDontSee('Wants shoes');
    }

    /**
     * Search must not become a way to read somebody else's leads.
     */
    public function test_search_never_reaches_another_owners_leads(): void
    {
        Lead::factory()->for(Agent::factory()->create())->create(['customer_name' => 'Чужая заявка']);

        $response = $this->actingAs($this->owner)->get(route('leads.index', ['q' => 'Чужая']));

        $response->assertOk();
        $response->assertDontSee('Чужая заявка');
    }

    public function test_search_combines_with_the_status_and_agent_filters(): void
    {
        $other = Agent::factory()->for($this->owner)->create(['name' => 'Clinic bot']);

        Lead::factory()->for($this->agent)->create(['customer_name' => 'Match new', 'items' => 'ковёр', 'status' => 'new']);
        Lead::factory()->for($this->agent)->create(['customer_name' => 'Match done', 'items' => 'ковёр', 'status' => 'done']);
        Lead::factory()->for($other)->create(['customer_name' => 'Other agent', 'items' => 'ковёр', 'status' => 'new']);

        $response = $this->actingAs($this->owner)->get(route('leads.index', [
            'q' => 'ковёр',
            'status' => 'new',
            'agent' => $this->agent->id,
        ]));

        $response->assertOk();
        $response->assertSee('Match new');
        $response->assertDontSee('Match done');
        $response->assertDontSee('Other agent');
    }

    public function test_a_blank_search_is_ignored_rather_than_matching_nothing(): void
    {
        Lead::factory()->for($this->agent)->create(['customer_name' => 'Still listed']);

        $this->actingAs($this->owner)
            ->get(route('leads.index', ['q' => '   ']))
            ->assertOk()
            ->assertSee('Still listed');
    }

    public function test_the_search_field_is_rendered_and_keeps_its_term(): void
    {
        $response = $this->actingAs($this->owner)->get(route('leads.index', ['q' => 'ковёр']));

        $response->assertOk();
        $response->assertSee('name="q"', false);
        $response->assertSee('value="ковёр"', false);
    }

    /**
     * The filter chips have to carry the search with them, or clicking one
     * silently throws the query away.
     */
    public function test_the_status_chips_preserve_the_active_search(): void
    {
        $response = $this->actingAs($this->owner)->get(route('leads.index', ['q' => 'ковёр']));

        $response->assertOk();
        // The href is Blade-escaped, so the raw route URL's "&" is "&amp;" here.
        $response->assertSee(e(route('leads.index', ['status' => 'new', 'q' => 'ковёр'])), false);
    }

    /**
     * The live search re-renders the result block from the same partial the
     * full page uses, so the two can never drift apart.
     */
    public function test_the_fragment_returns_only_the_result_block(): void
    {
        Lead::factory()->for($this->agent)->create(['customer_name' => 'Мадина Рахимова']);

        $response = $this->actingAs($this->owner)
            ->get(route('leads.index', ['q' => 'Мадина', 'fragment' => 1]));

        $response->assertOk();
        $response->assertSee('Мадина Рахимова');
        // No chrome: no layout, no page heading, no search field to re-render.
        $response->assertDontSee('<!DOCTYPE html>', false);
        $response->assertDontSee('name="q"', false);
        $response->assertDontSee(__('leads.index.heading'));
    }

    public function test_the_fragment_applies_the_same_filters_and_scoping(): void
    {
        $other = Agent::factory()->for($this->owner)->create();
        Lead::factory()->for($this->agent)->create(['customer_name' => 'Mine matching', 'status' => 'new']);
        Lead::factory()->for($other)->create(['customer_name' => 'Wrong agent', 'status' => 'new']);
        Lead::factory()->for(Agent::factory()->create())->create(['customer_name' => 'Not mine at all']);

        $response = $this->actingAs($this->owner)->get(route('leads.index', [
            'fragment' => 1,
            'status' => 'new',
            'agent' => $this->agent->id,
        ]));

        $response->assertOk();
        $response->assertSee('Mine matching');
        $response->assertDontSee('Wrong agent');
        $response->assertDontSee('Not mine at all');
    }

    public function test_a_guest_cannot_read_the_fragment(): void
    {
        Lead::factory()->for($this->agent)->create(['customer_name' => 'Секретная заявка']);

        $this->get(route('leads.index', ['fragment' => 1]))->assertRedirect();
    }

    /**
     * Typing must not keep re-marking leads as seen: the bell is cleared when
     * the page loads, and a write per keystroke would be pure waste.
     */
    public function test_the_fragment_does_not_re_mark_leads_as_seen(): void
    {
        $lead = Lead::factory()->for($this->agent)->create(['seen_at' => null]);

        $this->actingAs($this->owner)->get(route('leads.index', ['fragment' => 1]))->assertOk();

        $this->assertNull($lead->fresh()->seen_at);
    }

    /**
     * Pager links must not inherit `fragment=1`, or page 2 of a live search
     * would navigate the browser to a bare block of HTML.
     */
    public function test_pagination_links_never_carry_the_fragment_flag(): void
    {
        Lead::factory()->count(25)->for($this->agent)->create(['items' => 'ковёр']);

        $response = $this->actingAs($this->owner)
            ->get(route('leads.index', ['q' => 'ковёр', 'fragment' => 1]));

        $response->assertOk();
        $response->assertSee('page=2', false);
        $response->assertDontSee('fragment=1', false);
    }

    public function test_the_search_field_drives_the_live_filter(): void
    {
        $response = $this->actingAs($this->owner)->get(route('leads.index'));

        $response->assertOk();
        // Debounced per keystroke — no submit needed.
        $response->assertSee('@input.debounce.150ms="search($event.target.value)"', false);
        $response->assertSee('x-ref="results"', false);
        // The chips have to follow the typed term rather than the rendered one.
        $response->assertSee('data-lead-chip', false);
        $response->assertSee('syncChips(', false);
    }

    public function test_an_empty_result_explains_it_is_filtered(): void
    {
        Lead::factory()->for($this->agent)->create(['customer_name' => 'Something else']);

        $this->actingAs($this->owner)
            ->get(route('leads.index', ['q' => 'nothing-matches-this']))
            ->assertOk()
            ->assertSee(__('leads.index.empty_filtered_title'));
    }
}
