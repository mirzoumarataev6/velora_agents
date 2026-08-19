<?php

namespace Tests\Feature\Conversations;

use App\Models\Agent;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

/**
 * An agent's own Диалоги tab is the global inbox pinned to that agent — the
 * same partial, the same component, the same endpoints. These pin down that it
 * really is shared (not a second implementation) and that the pin holds.
 */
class AgentInboxTabTest extends TestCase
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

    protected function tab()
    {
        return $this->actingAs($this->owner)->get(route('agents.show', ['agent' => $this->agent, 'tab' => 'conversations']));
    }

    public function test_the_placeholder_is_gone(): void
    {
        $response = $this->tab();

        $response->assertOk();
        $response->assertDontSee('agents.conversations_tab.empty_title', false);
        $response->assertDontSee('Диалоги появятся здесь');
        // And the strings themselves no longer exist to be rendered.
        $this->assertFalse(Lang::has('agents.conversations_tab.empty_title'));
    }

    public function test_the_tab_renders_the_shared_inbox(): void
    {
        $response = $this->tab();

        $response->assertOk();
        $response->assertSee('conversationsInbox({', false);
        // The full feature set, not a cut-down copy.
        foreach (['all', 'unread', 'needs_human', 'has_lead', 'closed'] as $filter) {
            $response->assertSee('data-i18n="conversations.filters.'.$filter.'"', false);
        }
        $response->assertSee(__('conversations.index.search_placeholder'), false);
        $response->assertSee(__('conversations.thread.take_over'), false);
        $response->assertSee(__('conversations.thread.knowledge_used'), false);
        $response->assertSee(__('conversations.thread.leads_heading'), false);
        $response->assertSee('startResize(', false);
    }

    /**
     * The pin is what makes this the agent's tab rather than the global inbox.
     */
    public function test_the_inbox_is_locked_to_this_agent(): void
    {
        $response = $this->tab();

        $response->assertOk();
        $response->assertSee('lockedAgent: '.$this->agent->id, false);
        $response->assertSee('scoped: true', false);
    }

    public function test_the_global_page_stays_unscoped(): void
    {
        $response = $this->actingAs($this->owner)->get(route('conversations.index'));

        $response->assertOk();
        $response->assertSee('lockedAgent: null', false);
        $response->assertSee('scoped: false', false);
    }

    /**
     * Which agent answered is the point in the global inbox and noise in an
     * agent's own tab.
     */
    public function test_the_agent_picker_and_name_chip_are_dropped_when_scoped(): void
    {
        Agent::factory()->for($this->owner)->create(['name' => 'Clinic bot']);

        // Asserted on the markup, not the label: every locale's copy of
        // "all agents" ships in the page's i18n bundle either way.
        $tab = $this->tab();
        $tab->assertOk();
        $tab->assertDontSee('x-model="filters.agent"', false);
        $tab->assertDontSee('x-text="c.agent.name"', false);

        // The global inbox keeps both.
        $global = $this->actingAs($this->owner)->get(route('conversations.index'));
        $global->assertSee('x-model="filters.agent"', false);
        $global->assertSee('x-text="c.agent.name"', false);
    }

    /**
     * The single-row filter strip has to be identical in both places — it comes
     * from the shared partial, and this is what proves the tab didn't get a
     * stale two-row copy.
     */
    public function test_the_filter_strip_is_one_row_here_too(): void
    {
        $tab = $this->tab();

        $tab->assertOk();
        $this->assertSame(1, substr_count($tab->getContent(), 'data-filter-bar'));
        $tab->assertSee('flex-nowrap items-center', false);
        $tab->assertSee('overflow-x-auto', false);
        // Chips, search and both dates all present on that one strip.
        $tab->assertSee('data-i18n="conversations.filters.closed"', false);
        $tab->assertSee('type="search"', false);
        $this->assertSame(2, substr_count($tab->getContent(), 'type="date"'));
    }

    /**
     * Message search and jump-to-message come from the shared partial, so the
     * agent tab gets them without a second implementation.
     */
    public function test_message_search_and_jump_work_here_too(): void
    {
        $tab = $this->tab();

        $tab->assertOk();
        $tab->assertSee('@input.debounce.150ms="reload()"', false);
        $tab->assertSee('x-html="highlight(m.content, filters.q)"', false);
        $tab->assertSee('scrollToMatch()', false);
        $tab->assertSee('autoOpenTopMatch()', false);
        $tab->assertSee('nextMatch()', false);
    }

    /** The single clear button comes from the shared partial, so it matches. */
    public function test_the_search_field_has_a_single_clear_button_here_too(): void
    {
        $tab = $this->tab();

        $tab->assertOk();
        $tab->assertSee('[&::-webkit-search-cancel-button]:hidden', false);
        // The binding, not the function definition further down the page.
        $this->assertSame(1, substr_count($tab->getContent(), '@click="clearSearch()"'));
    }

    /**
     * One definition, included from two places — the whole reason the markup
     * was moved into a partial.
     */
    public function test_the_component_is_defined_exactly_once(): void
    {
        $this->assertSame(1, substr_count($this->tab()->getContent(), 'function conversationsInbox('));
        $this->assertSame(
            1,
            substr_count($this->actingAs($this->owner)->get(route('conversations.index'))->getContent(), 'function conversationsInbox(')
        );
    }

    /**
     * The tab must not boot on every agent page view — it only mounts when
     * opened, or each visit would fire a list request and start a poll.
     */
    public function test_the_inbox_only_mounts_when_the_tab_is_selected(): void
    {
        $response = $this->tab();

        $response->assertOk();
        $response->assertSee("x-if=\"tab === 'conversations'\"", false);
    }

    /**
     * The scoping is enforced server-side too, not just by the locked filter:
     * the tab's requests go to the same endpoint the global inbox uses.
     */
    public function test_the_scoped_list_returns_only_this_agents_conversations(): void
    {
        $mine = $this->conversationWith($this->agent, 'Mine');
        $other = Agent::factory()->for($this->owner)->create(['name' => 'Clinic bot']);
        $theirs = $this->conversationWith($other, 'Other agent');

        $response = $this->actingAs($this->owner)
            ->getJson(route('conversations.list', ['agent' => $this->agent->id]));

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($mine->id));
        $this->assertFalse($ids->contains($theirs->id));
    }

    public function test_another_owners_agent_tab_cannot_be_opened(): void
    {
        $stranger = Agent::factory()->create();

        $this->actingAs($this->owner)
            ->get(route('agents.show', ['agent' => $stranger, 'tab' => 'conversations']))
            ->assertForbidden();
    }

    protected function conversationWith(Agent $agent, string $customer): Conversation
    {
        $channel = Channel::factory()->for($agent)->create();
        $conversation = Conversation::factory()->for($agent)->for($channel)->create([
            'external_user_name' => $customer,
            'last_message_at' => now(),
        ]);
        Message::factory()->for($conversation)->create(['role' => 'user', 'content' => 'Hello']);

        return $conversation;
    }
}
