<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_welcome_page_loads_for_each_supported_locale(): void
    {
        // Asserts on a nav label rather than the auth links: the landing header
        // carries no "Log in" / "Start free" buttons any more, so those strings
        // only appear in the footer and are a weak signal that the PAGE is in
        // the right language.
        $this->get('/ru')->assertOk()->assertSee('Продукт');
        $this->get('/en')->assertOk()->assertSee('Product');
        $this->get('/tg')->assertOk()->assertSee('Маҳсулот');
    }

    public function test_locale_switcher_preserves_current_path(): void
    {
        $this->get('/ru/login')
            ->assertOk()
            ->assertSee('href="/en/login"', false)
            ->assertSee('href="/tg/login"', false);

        $this->get('/en/register')
            ->assertOk()
            ->assertSee('href="/ru/register"', false)
            ->assertSee('href="/tg/register"', false);
    }

    public function test_unsupported_locale_redirects_to_default(): void
    {
        $this->get('/fr')->assertRedirect('/tg');
        $this->get('/fr/login')->assertRedirect('/tg/login');
    }

    public function test_root_redirects_to_default_locale(): void
    {
        $this->get('/')->assertRedirect('/tg');
    }

    /**
     * The regression this guards: the dashboard has no /{locale}/ prefix, so the
     * switcher used to persist the choice with a background POST and navigate in
     * the same tick. The two requests raced, the navigation usually arrived
     * first, and the page rendered in the locale the server still had on record
     * — so the first click appeared to do nothing and only the second worked.
     *
     * The locale now travels on the navigation itself and must be applied to
     * that same response.
     */
    public function test_dashboard_locale_switch_applies_on_the_first_request(): void
    {
        $user = User::factory()->create(['locale' => 'ru']);
        Agent::factory()->for($user)->create();

        $response = $this->actingAs($user)->get('/agents?lang=en');

        $response->assertOk();
        // The response being switched — not the one after it — is the whole point.
        $response->assertSee('<html lang="en">', false);
        $response->assertSee('data-locale="en" aria-current="true"', false);
    }

    public function test_dashboard_locale_switch_works_first_try_for_every_locale(): void
    {
        $user = User::factory()->create(['locale' => 'ru']);
        Agent::factory()->for($user)->create();

        // Each switch is a single request from whatever the previous locale was,
        // including tg → en and back to ru, so no pair is quietly special-cased.
        foreach (['tg', 'en', 'ru', 'en', 'tg'] as $locale) {
            $this->actingAs($user)->get('/agents?lang='.$locale)
                ->assertOk()
                ->assertSee('<html lang="'.$locale.'">', false);
        }
    }

    public function test_dashboard_locale_switch_persists_to_the_user_and_later_requests(): void
    {
        $user = User::factory()->create(['locale' => 'ru']);
        Agent::factory()->for($user)->create();

        $this->actingAs($user)->get('/agents?lang=tg')->assertOk();

        $this->assertSame('tg', $user->fresh()->locale);

        // The clean URL the address bar is rewritten to must stay in tg.
        $this->actingAs($user)->get('/agents')
            ->assertOk()
            ->assertSee('<html lang="tg">', false);
    }

    public function test_unsupported_lang_query_leaves_the_current_locale_alone(): void
    {
        $user = User::factory()->create(['locale' => 'en']);
        Agent::factory()->for($user)->create();

        // Junk must be ignored, not treated as "no locale" and reset to Russian.
        $this->actingAs($user)->get('/agents?lang=fr')
            ->assertOk()
            ->assertSee('<html lang="en">', false);

        $this->assertSame('en', $user->fresh()->locale);
    }

    public function test_path_locale_beats_the_lang_query(): void
    {
        // A stale ?lang= carried into a shared link cannot contradict the
        // /{locale}/ page it points at.
        $this->get('/en/login?lang=tg')
            ->assertOk()
            ->assertSee('<html lang="en"', false);
    }

    public function test_switcher_form_fallback_returns_to_the_same_page_in_the_new_locale(): void
    {
        // With JS off the switcher is a plain POST. Coming from a prefixed page,
        // a bare back() would land on /ru/login again — whose prefix outranks the
        // session — and the switch would silently do nothing.
        $this->from('/ru/login')
            ->post('/locale', ['locale' => 'en'])
            ->assertRedirect('/en/login');

        $this->from('/tg')
            ->post('/locale', ['locale' => 'ru'])
            ->assertRedirect('/ru');
    }

    public function test_switcher_form_fallback_returns_unprefixed_pages_untouched(): void
    {
        $user = User::factory()->create(['locale' => 'ru']);
        Agent::factory()->for($user)->create();

        $this->actingAs($user)
            ->from('/agents')
            ->post('/locale', ['locale' => 'en'])
            ->assertRedirect('/agents');

        $this->assertSame('en', $user->fresh()->locale);
    }

    /**
     * The landing swaps in place from the embedded bundle and must keep doing
     * so: it only needs the choice recorded, and a redirect body would be waste.
     */
    public function test_landing_instant_switch_persists_without_a_redirect(): void
    {
        $this->get('/ru')->assertOk();

        $this->postJson('/locale', ['locale' => 'en'])->assertNoContent();

        $this->assertSame('en', session('locale'));

        // And the stored choice is what a later unprefixed entry follows.
        $this->get('/')->assertRedirect('/en');
    }
}
