<?php

namespace Tests\Feature\Frontend;

use App\Models\Agent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PHASE 2 item 13: every dashboard page always loaded agents.php's full
 * ~70KB-per-locale content (~210KB across the three the instant-switch
 * bundle embeds) for 15 sidebar nav strings — the rest of that file is the
 * agent builder/list/detail pages' own content, unrelated to CRM/BI/
 * Projects/etc. Measured before fixing: /projects 441,834 bytes total,
 * 380,332 of it the i18n bundle (86%). After moving the nav strings to
 * shell.php and dropping 'agents' from the base namespace list: 134,046
 * bytes total, 72,566 the bundle (54%) — a 307,788-byte drop, same page,
 * same visible content, no behaviour change. /agents itself is unchanged
 * (it adds 'agents' back via its own $i18nExtraNamespaces, same as always).
 *
 * This doesn't re-measure the exact byte counts (brittle — any copy change
 * shifts them) but guards the actual mechanism: a non-agent dashboard page
 * must not embed agents.php's own content in the i18n bundle.
 */
class I18nBundleSizeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Checked structurally — `"ru":{"agents":{` — not against the actual
     * Cyrillic value: @json() in the bundle script escapes non-ASCII as
     * \uXXXX, so a plain substring check against a translated string would
     * pass trivially whether or not the namespace is really there (see the
     * same note in the existing I18nBundleTest).
     */
    private function embedsNamespace(string $html, string $namespace): bool
    {
        return str_contains($html, '"'.$namespace.'":{');
    }

    public function test_a_projects_page_does_not_embed_the_agents_namespace(): void
    {
        $user = User::factory()->create();

        $html = $this->actingAs($user)->get(route('projects.index'))->assertOk()->getContent();

        $this->assertFalse($this->embedsNamespace($html, 'agents'));
        $this->assertTrue($this->embedsNamespace($html, 'shell'));
    }

    public function test_a_crm_page_does_not_embed_the_agents_namespace(): void
    {
        $user = User::factory()->create();

        $html = $this->actingAs($user)->get(route('crm.board'))->assertOk()->getContent();

        $this->assertFalse($this->embedsNamespace($html, 'agents'));
    }

    public function test_the_agents_index_page_still_embeds_its_own_namespace(): void
    {
        $user = User::factory()->create();

        $html = $this->actingAs($user)->get(route('agents.index'))->assertOk()->getContent();

        $this->assertTrue($this->embedsNamespace($html, 'agents'));
    }

    public function test_the_agent_detail_page_still_embeds_its_own_namespace(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();

        $html = $this->actingAs($user)->get(route('agents.show', $agent))->assertOk()->getContent();

        $this->assertTrue($this->embedsNamespace($html, 'agents'));
    }
}
