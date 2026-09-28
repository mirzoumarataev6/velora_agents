<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Search on the Заявки page. The three searchable columns are the three things
 * an owner actually remembers a lead by: who they are, how to reach them, and
 * what they asked for.
 */
class LeadSearchTest extends TestCase
{
    use RefreshDatabase;

    private ?string $inertiaVersion = null;

    protected User $owner;

    protected Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->agent = Agent::factory()->for($this->owner)->create(['name' => 'Shop bot']);
    }

    /**
     * The current asset version.
     *
     * Read off a normal page load: asking for it with an `X-Inertia` header
     * and no version answers 409 by design, and recomputing it here would make
     * these fail for the wrong reason the next time the bundle is rebuilt.
     *
     * NOTE this performs a full page load, which clears the unread bell — so a
     * test that cares about `seen_at` must call it before creating the lead.
     */
    protected function inertiaVersion(): string
    {
        // Memoised: reading it costs a full page load, and a second one inside
        // partialReload() would clear the bell the caller is measuring.
        if ($this->inertiaVersion !== null) {
            return $this->inertiaVersion;
        }

        $version = '';

        $this->actingAs($this->owner)
            ->get(route('leads.index'))
            ->assertInertia(function (AssertableInertia $page) use (&$version) {
                $version = $page->toArray()['version'];
            });

        return $this->inertiaVersion = $version;
    }

    /**
     * Ask for named props only — exactly what the search box does.
     *
     * The asset version is read back from a real Inertia response rather than
     * recomputed here: a mismatch answers 409 by design, and hard-coding it
     * would make this test fail for the wrong reason the next time the bundle
     * is rebuilt.
     *
     * @param  array<string, mixed>  $query
     */
    protected function partialReload(array $query, string $only): TestResponse
    {
        return $this->actingAs($this->owner)->get(route('leads.index', $query), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $this->inertiaVersion(),
            'X-Inertia-Partial-Component' => 'Leads/Index',
            'X-Inertia-Partial-Data' => $only,
        ]);
    }

    /**
     * The values a given prop carries on the rendered page.
     *
     * The list renders in the browser now, so "is this lead on the page" is a
     * question about `leads.data` rather than about the HTML — and asserting
     * the prop is stricter, since a name appearing anywhere in the markup
     * (a filter chip, a title) could satisfy the old assertSee.
     *
     * @return list<mixed>
     */
    protected function listed(TestResponse $response, string $field = 'customer_name'): array
    {
        $values = [];

        $response->assertInertia(function (AssertableInertia $page) use (&$values, $field) {
            $values = array_column($page->toArray()['props']['leads']['data'], $field);
        });

        return $values;
    }

    public function test_search_matches_the_customer_name(): void
    {
        Lead::factory()->for($this->agent)->create(['customer_name' => 'Мадина Рахимова']);
        Lead::factory()->for($this->agent)->create(['customer_name' => 'Анвар Каримов']);

        $response = $this->actingAs($this->owner)->get(route('leads.index', ['q' => 'Мадина']));

        $response->assertOk();
        $listed = $this->listed($response, 'customer_name');
        $this->assertContains('Мадина Рахимова', $listed);
        $this->assertNotContains('Анвар Каримов', $listed);
    }

    public function test_search_matches_the_contact(): void
    {
        Lead::factory()->for($this->agent)->create(['customer_name' => 'Findable', 'contact' => '+992900112233']);
        Lead::factory()->for($this->agent)->create(['customer_name' => 'Hidden', 'contact' => '+992555000111']);

        $response = $this->actingAs($this->owner)->get(route('leads.index', ['q' => '900112233']));

        $response->assertOk();
        $listed = $this->listed($response);
        $this->assertContains('Findable', $listed);
        $this->assertNotContains('Hidden', $listed);
    }

    public function test_search_matches_what_the_customer_wanted(): void
    {
        Lead::factory()->for($this->agent)->create(['customer_name' => 'Wants a dress', 'items' => 'Платье синее, 42']);
        Lead::factory()->for($this->agent)->create(['customer_name' => 'Wants shoes', 'items' => 'Туфли, 38']);

        $response = $this->actingAs($this->owner)->get(route('leads.index', ['q' => 'Платье']));

        $response->assertOk();
        $listed = $this->listed($response);
        $this->assertContains('Wants a dress', $listed);
        $this->assertNotContains('Wants shoes', $listed);
    }

    /**
     * Search must not become a way to read somebody else's leads.
     */
    public function test_search_never_reaches_another_owners_leads(): void
    {
        Lead::factory()->for(Agent::factory()->create())->create(['customer_name' => 'Чужая заявка']);

        $response = $this->actingAs($this->owner)->get(route('leads.index', ['q' => 'Чужая']));

        $response->assertOk();
        $this->assertNotContains('Чужая заявка', $this->listed($response));
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
        $listed = $this->listed($response);
        $this->assertContains('Match new', $listed);
        $this->assertNotContains('Match done', $listed);
        $this->assertNotContains('Other agent', $listed);
    }

    public function test_a_blank_search_is_ignored_rather_than_matching_nothing(): void
    {
        Lead::factory()->for($this->agent)->create(['customer_name' => 'Still listed']);

        $this->actingAs($this->owner)
            ->get(route('leads.index', ['q' => '   ']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('leads.data.0.customer_name', 'Still listed'));
    }

    public function test_the_search_field_is_rendered_and_keeps_its_term(): void
    {
        $response = $this->actingAs($this->owner)->get(route('leads.index', ['q' => 'ковёр']));

        $response->assertOk();
        // The field renders from this prop and writes back to the same query
        // parameter, so the term surviving the round trip is the contract.
        $response->assertInertia(fn (AssertableInertia $page) => $page->where('activeSearch', 'ковёр'));
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
        // Every filter navigation on the page is built from these props in one
        // place (Pages/Leads/Index.vue's visit()), so a status change carrying
        // the search is a question of the search still being in props.
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->where('activeSearch', 'ковёр')
            ->has('statusOptions'));
    }

    /**
     * The live search re-renders the result block from the same partial the
     * full page uses, so the two can never drift apart.
     */
    /**
     * Typing re-fetches the RESULTS and nothing else.
     *
     * This used to be a `?fragment=1` route that returned a chrome-less block
     * of HTML, which the page spliced in by hand. Inertia does the same job
     * properly: the client asks for named props, the server sends only those,
     * and there is no second rendering path that can drift from the first.
     */
    public function test_a_search_reload_returns_only_the_result_props(): void
    {
        Lead::factory()->for($this->agent)->create(['customer_name' => 'Мадина Рахимова']);

        $response = $this->partialReload(['q' => 'Мадина'], 'leads,activeSearch');

        $response->assertOk();

        $props = $response->json('props');

        $this->assertSame(['Мадина Рахимова'], array_column($props['leads']['data'], 'customer_name'));
        // No chrome came back with it — not the agent list, not the tab count.
        $this->assertArrayNotHasKey('agents', $props);
        $this->assertArrayNotHasKey('pendingHaggleCount', $props);
    }

    /** A partial reload runs through the same filters and the same scoping. */
    public function test_a_partial_reload_applies_the_same_filters_and_scoping(): void
    {
        $other = Agent::factory()->for($this->owner)->create();
        Lead::factory()->for($this->agent)->create(['customer_name' => 'Mine matching', 'status' => 'new']);
        Lead::factory()->for($other)->create(['customer_name' => 'Wrong agent', 'status' => 'new']);
        Lead::factory()->for(Agent::factory()->create())->create(['customer_name' => 'Not mine at all']);

        $response = $this->actingAs($this->owner)->get(route('leads.index', [
            'status' => 'new',
            'agent' => $this->agent->id,
        ]));

        $response->assertOk();

        $listed = $this->listed($response);
        $this->assertContains('Mine matching', $listed);
        $this->assertNotContains('Wrong agent', $listed);
        $this->assertNotContains('Not mine at all', $listed);
    }

    public function test_a_guest_cannot_read_the_list(): void
    {
        Lead::factory()->for($this->agent)->create(['customer_name' => 'Секретная заявка']);

        $this->get(route('leads.index'))->assertRedirect();
    }

    /**
     * Typing must not keep re-marking leads as seen: the bell is cleared when
     * the page loads, and a write per keystroke would be pure waste.
     */
    public function test_a_partial_reload_does_not_re_mark_leads_as_seen(): void
    {
        // Warm the version BEFORE the lead exists: reading it costs a full page
        // load, which is exactly the thing that clears the bell.
        $this->inertiaVersion();

        $lead = Lead::factory()->for($this->agent)->create(['seen_at' => null]);

        // Exactly what the search box sends. The controller keys off Inertia's
        // own partial-data header, so this cannot drift from the real request.
        $this->partialReload([], 'leads')->assertOk();

        $this->assertNull($lead->fresh()->seen_at, 'A keystroke must not cost a write.');

        // A full page load still clears the bell.
        $this->actingAs($this->owner)->get(route('leads.index'))->assertOk();

        $this->assertNotNull($lead->fresh()->seen_at);
    }

    /**
     * Paging a search stays a search.
     *
     * The old hazard here was `fragment=1` leaking into pager hrefs, which sent
     * the browser to a bare block of HTML. There are no pager hrefs now — the
     * page rebuilds the query from props — so what is left to protect is that
     * paging carries the term rather than silently resetting it.
     */
    public function test_paging_a_search_keeps_the_term(): void
    {
        Lead::factory()->count(25)->for($this->agent)->create(['items' => 'ковёр']);

        $this->actingAs($this->owner)
            ->get(route('leads.index', ['q' => 'ковёр', 'page' => 2]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('activeSearch', 'ковёр')
                ->where('leads.current_page', 2)
                ->where('leads.last_page', 2));
    }

    /**
     * The field searches as you type, debounced, without a submit — and asks
     * for the results alone rather than the whole page.
     *
     * Asserted at the source, because both halves are client-side: a PHP
     * request cannot observe a debounce, and the partial-data header the
     * previous test relies on is only sent if this option is set.
     */
    public function test_the_search_field_drives_a_debounced_partial_reload(): void
    {
        $page = file_get_contents(resource_path('js/Pages/Leads/Index.vue'));

        $this->assertStringContainsString('setTimeout(', $page);
        $this->assertStringContainsString("only: ['leads', 'activeSearch']", $page);
        $this->assertStringContainsString('watch(search', $page);
    }

    public function test_an_empty_result_explains_it_is_filtered(): void
    {
        Lead::factory()->for($this->agent)->create(['customer_name' => 'Something else']);

        // An empty list PLUS an active filter is what the page renders the
        // "nothing found, try another filter" state from — as opposed to the
        // "no leads yet" state, which the same list without a filter gets.
        $this->actingAs($this->owner)
            ->get(route('leads.index', ['q' => 'nothing-matches-this']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->count('leads.data', 0)
                ->where('activeSearch', 'nothing-matches-this'));
    }
}
