<?php

namespace Tests\Feature\Frontend;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The component library and the gallery that reviews it.
 *
 * Most of a component library is checked by looking at it, which is what
 * /design-system is for. These pin the two things looking at it would NOT
 * catch — both of which are silent failures that leave the page rendering
 * perfectly while doing nothing.
 */
class DesignSystemTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_gallery_renders_for_a_signed_in_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/design-system')
            ->assertOk()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->component('DesignSystem')
                    ->has('auth.user')
                    ->has('translations.common')
            );
    }

    /**
     * Every `--duration-*` token must have a matching `@utility`.
     *
     * Tailwind v4 has an `--ease-*` theme namespace but NO `--duration-*` one:
     * `duration-*` accepts a bare number or an arbitrary value and nothing
     * else. Declaring `--duration-fast` in `@theme` therefore produced a token
     * that two dozen components referenced as `duration-fast` and that
     * generated no CSS whatsoever — the classes were inert and every transition
     * quietly fell back to the browser default.
     *
     * Nothing about the rendered page looks wrong when this happens, which is
     * exactly why it needs a test rather than an eye.
     */
    public function test_every_duration_token_has_a_real_utility(): void
    {
        $tokens = file_get_contents(resource_path('css/tokens.css'));

        preg_match_all('/--duration-([a-z]+):/', $tokens, $declared);
        $this->assertNotEmpty($declared[1], 'No duration tokens found — has the token file moved?');

        foreach (array_unique($declared[1]) as $name) {
            $this->assertMatchesRegularExpression(
                '/@utility\s+duration-'.preg_quote($name, '/').'\s*\{/',
                $tokens,
                "--duration-{$name} is declared but has no @utility, so `duration-{$name}` generates no CSS."
            );
        }
    }

    /**
     * The theme selectors must stay unanchored from `:root`.
     *
     * `.dark` and `[data-mode="stage"]`, not `:root.dark` — an unanchored
     * selector is what lets a NESTED element open a theme island, which is the
     * only reason the gallery can show one component in light, dark and stage
     * side by side. Re-anchoring them to :root would not break the app at all;
     * it would silently collapse all three preview panes to the page's own
     * theme, and the gallery would still look fine.
     */
    public function test_theme_selectors_stay_scopable_to_a_nested_element(): void
    {
        $tokens = file_get_contents(resource_path('css/tokens.css'));

        $this->assertMatchesRegularExpression('/^\.dark \{/m', $tokens);
        $this->assertMatchesRegularExpression("/^\[data-mode='stage'\] \{/m", $tokens);
        $this->assertDoesNotMatchRegularExpression(
            '/^:root\.dark/m',
            $tokens,
            'Anchoring the dark theme to :root collapses the design-system gallery to a single mode.'
        );
    }

    /**
     * The dashboard's live panel must never open a conversation.
     *
     * `conversations.show` returns the full transcript and is the obvious
     * endpoint to reach for — but it STAMPS `operator_read_at`: opening a
     * conversation is what marks it read. That is correct for the inbox and
     * catastrophic on a dashboard, which polls every twenty seconds: a panel
     * left open on a second monitor would quietly mark every incoming
     * conversation as read and empty the unread badge without anyone having
     * looked at anything.
     *
     * The panel uses the read-only `conversations.list`, whose `preview` field
     * already carries the last message. This is a source-level check because
     * the failure is invisible from the server: the dashboard would render
     * perfectly and the damage would show up days later as an inbox that never
     * has anything unread.
     */
    public function test_the_live_dashboard_panel_never_calls_the_read_marking_endpoint(): void
    {
        $source = file_get_contents(resource_path('js/Components/dashboard/LiveConversation.vue'));

        $this->assertStringContainsString("route('conversations.list')", $source);
        $this->assertStringNotContainsString(
            "route('conversations.show'",
            $source,
            'conversations.show marks the conversation read — a polling dashboard must not call it.'
        );
    }

    /**
     * Components use semantic tokens, never a raw hex.
     *
     * The palette guard in BrandConsistencyTest renders pages and checks the
     * emitted HTML, which cannot see a colour baked into a Vue file that no
     * test happens to render. This reads the components themselves.
     *
     * It looks only where a colour would actually PAINT something — a class
     * binding, an inline style, or a <style> block. A hex in a comment or in a
     * prose description is documentation (the gallery names #38BDF8 when
     * explaining what the AI accent is), and flagging those would train the
     * next person to ignore this test.
     */
    public function test_no_component_hardcodes_a_colour_in_a_styling_position(): void
    {
        $offenders = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('js'), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'vue') {
                continue;
            }

            // The brand mark IS the artwork, and the two auth pages reproduce
            // Google's own logo — a third-party identity is not ours to re-skin.
            if (in_array($file->getFilename(), ['VeloraMark.vue', 'Login.vue', 'Register.vue'], true)) {
                continue;
            }

            $source = file_get_contents($file->getPathname());

            // Strip comments first, so a hex being EXPLAINED is never a hit.
            $source = preg_replace(['#/\*.*?\*/#s', '#<!--.*?-->#s'], '', $source);

            preg_match_all(
                '/(?::?class|style)="([^"]*)"/',
                $source,
                $attributes
            );

            $styling = $attributes[1];

            if (preg_match('#<style[^>]*>(.*?)</style>#s', $source, $block)) {
                $styling[] = $block[1];
            }

            foreach ($styling as $value) {
                if (preg_match('/#[0-9a-fA-F]{6}\b/', $value, $match)) {
                    $offenders[] = $file->getFilename().' → '.$match[0];
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "These components paint their own colour instead of using a token:\n".implode("\n", $offenders)
        );
    }
}
