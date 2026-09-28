<?php

namespace Tests\Feature\Settings;

use App\Http\Middleware\SetLocale;
use App\Models\Agent;
use App\Models\Channel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The page itself: that it renders every section, that the sidebar now links to
 * it as a real destination, and that all three locales ship with it so the
 * instant switch has something to switch to.
 */
class SettingsPageTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_the_page_renders_all_four_sections(): void
    {
        $response = $this->actingAs($this->user)->get('/settings');

        $response->assertOk()->assertInertia(function (AssertableInertia $page) {
            $page->component('Settings/Index');

            // The page builds its four tabs from these labels, so all four
            // have to reach it. They are genuinely different KINDS of change,
            // which is why they are tabs rather than one long form.
            foreach (['profile', 'security', 'preferences', 'account'] as $tab) {
                $page->has("translations.settings.tabs.{$tab}");
            }
        });
    }

    /**
     * The Telegram lead-alert card is the per-agent flow reused here, one card
     * per agent — so the page has to render it with real agents present.
     */
    public function test_a_telegram_alert_card_is_rendered_for_each_agent(): void
    {
        $first = Agent::factory()->for($this->user)->create(['name' => 'Shop bot']);
        $second = Agent::factory()->for($this->user)->create(['name' => 'Clinic bot']);
        Channel::factory()->for($first)->create(['type' => 'telegram', 'status' => 'connected']);

        $response = $this->actingAs($this->user)->get('/settings');

        // One card per agent, because the link is made through that agent's
        // own bot — there is no product-wide Telegram connection to hang it on.
        $response->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->count('agents', 2)
            ->where('agents.0.name', 'Clinic bot')
            ->where('agents.1.name', 'Shop bot')
            ->where('agents.1.linked', false));
    }

    public function test_only_the_signed_in_users_agents_are_offered(): void
    {
        Agent::factory()->for($this->user)->create(['name' => 'Mine']);
        Agent::factory()->for(User::factory()->create())->create(['name' => 'Someone elses']);

        $response = $this->actingAs($this->user)->get('/settings');

        $response->assertOk();
        $response->assertSee('Mine');
        $response->assertDontSee('Someone elses');
    }

    public function test_the_sidebar_links_to_settings_instead_of_showing_a_soon_badge(): void
    {
        $response = $this->actingAs($this->user)->get('/settings');

        $response->assertOk();

        // The rail builds every link from a named route through Ziggy, so what
        // the server owes is the route being in the list it ships.
        $this->assertStringContainsString(
            "route: 'settings.index'",
            file_get_contents(resource_path('js/Components/AppSidebar.vue')),
        );
        // The placeholder badge is gone from the chrome entirely.
        $this->assertStringNotContainsString(
            'agents.nav.soon',
            file_get_contents(resource_path('js/Components/AppSidebar.vue')),
        );
    }

    public function test_the_page_embeds_every_locale_for_the_instant_switch(): void
    {
        $response = $this->actingAs($this->user)->get('/settings');

        // ONE locale now, not three. Switching is an Inertia visit that
        // replaces the translations prop, so embedding every locale in every
        // page — which is what the Blade bundle did — has no purpose left.
        $response->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('locale.supported', SetLocale::SUPPORTED_LOCALES)
            ->has('translations.settings.tabs')
            ->where('translations.settings.index.heading', __('settings.index.heading')));
    }

    /**
     * A key present in one locale but missing in another silently falls back to
     * printing the key path at the user. Every new namespace is checked here.
     */
    #[DataProvider('translatedNamespaces')]
    public function test_every_locale_defines_the_same_keys(string $namespace): void
    {
        $reference = SetLocale::SUPPORTED_LOCALES[0];
        $expected = $this->flatten(trans($namespace, [], $reference));

        $this->assertNotEmpty($expected, "Namespace '{$namespace}' is empty in '{$reference}'.");

        foreach (SetLocale::SUPPORTED_LOCALES as $locale) {
            $actual = $this->flatten(trans($namespace, [], $locale));

            $this->assertSame(
                [],
                array_values(array_diff($expected, $actual)),
                "Locale '{$locale}' is missing keys from '{$namespace}'."
            );
            $this->assertSame(
                [],
                array_values(array_diff($actual, $expected)),
                "Locale '{$locale}' defines keys absent from '{$reference}' in '{$namespace}'."
            );
        }
    }

    /** @return array<int, array<int, string>> */
    public static function translatedNamespaces(): array
    {
        return [['settings'], ['leads'], ['conversations']];
    }

    /**
     * @param  array<string, mixed>  $messages
     * @return array<int, string>
     */
    protected function flatten(array $messages, string $prefix = ''): array
    {
        $keys = [];

        foreach ($messages as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_array($value)) {
                $keys = array_merge($keys, $this->flatten($value, $path));
            } else {
                $keys[] = $path;
            }
        }

        sort($keys);

        return $keys;
    }
}
