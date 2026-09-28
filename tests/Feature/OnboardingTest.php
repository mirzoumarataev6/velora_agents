<?php

namespace Tests\Feature;

use App\Http\Controllers\AuthController;
use App\Models\Agent;
use App\Models\Channel;
use App\Models\KnowledgeSource;
use App\Models\User;
use App\Services\Llm\LlmProviderInterface;
use App\Services\OnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The first-run experience: a one-time welcome, a greeting for returning
 * owners, and a checklist whose progress is derived from the account's real
 * data rather than tracked separately.
 *
 * The load-bearing property is "exactly once": the welcome is stamped by the
 * request that renders it, so no reload, tab close or failed fetch can bring
 * it back. These tests pin that, and pin that the checklist can never claim
 * something the dashboard behind it contradicts.
 */
class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function newcomer(): User
    {
        return User::factory()->newcomer()->create();
    }

    /**
     * Act as a user whose session began with a registration — the only kind of
     * session allowed to see the checklist.
     *
     * Registering for real (POST /register) is covered separately below; this
     * shortcut is for the many cases that care about what the checklist SAYS
     * rather than how the session came to be marked.
     */
    protected function actingAsNewRegistration(User $user): static
    {
        return $this->actingAs($user)
            ->withSession([AuthController::ONBOARDING_SESSION_KEY => true]);
    }

    /**
     * A reply the sandbox will accept, so the last step can be completed the
     * way an owner completes it — by using the product.
     */
    protected function stubLlm(): void
    {
        $this->mock(LlmProviderInterface::class, function ($mock) {
            $mock->shouldReceive('embed')->andReturn(['vector' => [1, 0, 0], 'input_tokens' => 3]);
            $mock->shouldReceive('generate')->andReturn([
                'text' => 'Здравствуйте!',
                'input_tokens' => 20,
                'output_tokens' => 4,
                'finish_reason' => 'STOP',
            ]);
        });
    }

    // ---------------------------------------------------------------- welcome

    public function test_a_new_account_is_welcomed_by_name(): void
    {
        $user = $this->newcomer();

        $this->actingAs($user)->get(route('agents.index'))
            ->assertOk()
            // `welcome` is the shared prop the modal renders from; reading it
            // is what stamps the account, so it is also what "exactly once"
            // is measured on.
            ->assertInertia(fn (AssertableInertia $page) => $page->where('welcome', $user->name));
    }

    /**
     * The whole point of the feature. The stamp is written while rendering, so
     * a second page view — even one the owner never dismissed the modal on —
     * is already past it.
     */
    public function test_the_welcome_is_shown_exactly_once(): void
    {
        $user = $this->newcomer();

        $this->actingAs($user)->get(route('agents.index'))->assertOk();

        $this->assertNotNull($user->fresh()->welcomed_at);

        $this->actingAs($user)->get(route('agents.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('welcome', null));
    }

    /**
     * Landing anywhere in the dashboard counts, not just on Agents — the
     * welcome is raised by the shared layout.
     */
    public function test_the_welcome_survives_landing_on_another_dashboard_page(): void
    {
        $user = $this->newcomer();

        $this->actingAs($user)->get(route('settings.index'))
            ->assertOk()
            // Any Inertia page owes it: `welcome` is a SHARED prop, so the
            // modal follows the owner to whatever they land on first.
            ->assertInertia(fn (AssertableInertia $page) => $page->where('welcome', $user->name));

        $this->assertNotNull($user->fresh()->welcomed_at);
    }

    public function test_registering_produces_a_welcomed_state_and_no_welcome_back_toast(): void
    {
        $response = $this->post('/register', [
            'name' => 'Nadia',
            'email' => 'nadia@example.test',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ]);

        $response->assertRedirect(route('home'));
        $response->assertSessionHasNoErrors();
        $this->assertNotSame(
            AuthController::GREETING_BACK,
            session(AuthController::GREETING_SESSION_KEY),
            'A sign-up must never be greeted as a returning owner.',
        );

        $user = User::where('email', 'nadia@example.test')->firstOrFail();
        $this->assertNull($user->welcomed_at, 'A fresh sign-up is still owed its welcome.');
    }

    public function test_registering_owes_the_new_owner_the_welcome_greeting(): void
    {
        $this->post('/register', [
            'name' => 'Nadia',
            'email' => 'nadia@example.test',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ])->assertSessionHas(AuthController::GREETING_SESSION_KEY, AuthController::GREETING_WELCOME);
    }

    public function test_the_sign_up_greeting_falls_back_to_a_toast_without_a_checklist(): void
    {
        // An established, already-welcomed account: no checklist on the page to
        // carry the banner, and no modal (whose title contains the same words),
        // so only the toast can satisfy this.
        $user = User::factory()->create(['name' => 'Nadia']);

        $response = $this->actingAs($user)
            ->withSession([AuthController::GREETING_SESSION_KEY => AuthController::GREETING_WELCOME])
            ->get(route('agents.index'));

        $response->assertOk();
        // No checklist to pin a banner to, so the greeting falls through to
        // the shared `flash.greeting` prop, which the layout toasts.
        $response->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('banner', null)
                ->where('flash.greeting', __('onboarding.welcome_greeting', ['name' => 'Nadia']))
        );
    }

    public function test_the_greeting_is_shown_once_and_then_spent(): void
    {
        $user = User::factory()->create(['name' => 'Ada']);

        $this->actingAs($user)
            ->withSession([AuthController::GREETING_SESSION_KEY => AuthController::GREETING_BACK])
            ->get(route('agents.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('flash.greeting', __('onboarding.back', ['name' => 'Ada'])));

        // Spent by the response that carried it.
        $this->actingAs($user)->get(route('agents.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('flash.greeting', null));
    }

    /**
     * The greeting survives the redirect chain a real sign-up walks.
     *
     * This used to model something more fragile: the auth forms POSTed with
     * fetch(), which followed the redirects itself and DISCARDED the resulting
     * HTML, so anything spent by those invisible hops was gone before the owner
     * saw a page. The forms are Inertia visits now, and Inertia follows the
     * chain as ordinary requests — only the final page renders, so only it can
     * spend the greeting. The property is the same; the hazard is gone.
     */
    public function test_the_sign_up_greeting_survives_the_redirect_chain(): void
    {
        // The POST answers with a redirect, which renders nothing — so it
        // cannot spend the greeting. Only a page that actually renders can,
        // and that is the property this pins.
        $this->post('/register', [
            'name' => 'Nadia',
            'email' => 'nadia@example.test',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ])->assertRedirect(route('home'));

        $this->assertSame(
            AuthController::GREETING_WELCOME,
            session(AuthController::GREETING_SESSION_KEY),
            'A redirect must not consume the greeting.',
        );

        // A sign-up lands with a checklist, so the first page that DOES render
        // turns the greeting into the banner rather than a toast.
        $this->get(route('agents.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('banner', 'Nadia'));
    }

    // ------------------------------------------------------- greeting banner

    /**
     * After a sign-up the greeting is the banner on the checklist — and ONLY
     * the banner. The toast and the welcome modal both greet by name, so
     * either one alongside it would greet the same person twice.
     */
    public function test_the_sign_up_greeting_is_a_banner_on_the_checklist_and_nothing_else(): void
    {
        $this->registerAndLand('Nadia')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                // The banner claims it...
                ->where('banner', 'Nadia')
                ->where('onboarding.visible', true)
                // ...which leaves nothing for the toast, and nothing for the
                // welcome modal. Both greet by name; either alongside the
                // banner would greet the same person twice.
                ->where('flash.greeting', null)
                ->where('welcome', null));
    }

    public function test_the_banner_sits_at_the_top_of_the_checklist_and_can_be_closed(): void
    {
        $this->registerAndLand('Nadia')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('banner', 'Nadia'));

        // Ordering and nesting are the page's job now, so they are asserted
        // where they are decided: the banner is a sibling ABOVE the checklist
        // card, not a strip inside it, and its × records nothing — which is
        // what makes the reload in the next test render the checklist again.
        $page = file_get_contents(resource_path('js/Pages/Agents/Index.vue'));

        $this->assertMatchesRegularExpression(
            '/<WelcomeBanner[^>]*\/>\s*<OnboardingCard/s',
            $page,
            'The greeting must be pinned above the checklist, as its own block.',
        );

        $this->assertStringContainsString(
            '@click="shown = false"',
            file_get_contents(resource_path('js/Components/onboarding/WelcomeBanner.vue')),
        );
    }

    public function test_the_banner_lasts_one_page_view_and_leaves_nothing_dismissed(): void
    {
        $this->registerAndLand('Nadia');

        // A reload: the greeting is over, but the checklist it sat on is not.
        $this->get(route('agents.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('banner', null)
                ->where('onboarding.visible', true));
    }

    /** Register, then land on the agents screen the way a sign-up does. */
    protected function registerAndLand(string $name): TestResponse
    {
        $this->post('/register', [
            'name' => $name,
            'email' => Str::slug($name).'@example.test',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ]);

        return $this->get(route('agents.index'));
    }

    public function test_the_sign_in_greeting_survives_the_redirect_chain(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.test', 'name' => 'Ada']);

        $this->post('/login', ['email' => 'ada@example.test', 'password' => 'password']);

        // An established account has no checklist, so this one is a toast.
        $this->actingAs($user)->get(route('agents.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('banner', null)
                ->where('flash.greeting', __('onboarding.back', ['name' => 'Ada'])));
    }

    public function test_an_established_account_is_never_shown_the_welcome(): void
    {
        // The factory default mirrors the migration's backfill: existing
        // accounts must not be greeted as new arrivals when this ships.
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('agents.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('welcome', null));
    }

    // ----------------------------------------------------------- welcome back

    public function test_logging_in_greets_the_returning_owner(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.test']);

        $response = $this->post('/login', [
            'email' => 'ada@example.test',
            'password' => 'password',
        ]);

        $response->assertSessionHas(AuthController::GREETING_SESSION_KEY, AuthController::GREETING_BACK);

        $this->actingAs($user->fresh())->get(route('agents.index'));
    }

    public function test_the_greeting_renders_as_a_self_dismissing_toast(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.test']);

        $this->actingAs($user)
            ->withSession([AuthController::GREETING_SESSION_KEY => AuthController::GREETING_BACK])
            ->get(route('agents.index'))
            ->assertOk()
            // A toast, not a banner: the layout raises it from this prop and
            // ToastHost expires it on its own.
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('flash.greeting', __('onboarding.back', ['name' => $user->name])));
    }

    public function test_no_greeting_without_a_fresh_sign_in(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('agents.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('flash.greeting', null));
    }

    // -------------------------------------------------------------- checklist

    public function test_the_checklist_starts_at_zero_of_four_for_a_new_account(): void
    {
        $user = $this->newcomer();

        $this->actingAsNewRegistration($user)->get(route('agents.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('onboarding.completed', 0)
                ->where('onboarding.total', 4)
                // Which step carries the button is derived from `done` by
                // OnboardingCard.vue — it gives one to the FIRST incomplete
                // step and to no other.
                ->where('onboarding.steps.0.done', false)
                ->where('onboarding.steps.2.done', false));
    }

    /**
     * Derived, not stored: work done before the checklist was ever rendered
     * still counts, which is the anti-pattern this design exists to avoid.
     */
    public function test_progress_reflects_work_already_done(): void
    {
        $user = $this->newcomer();
        $agent = Agent::factory()->for($user)->create();
        KnowledgeSource::factory()->for($agent)->create();

        $this->actingAsNewRegistration($user)->get(route('agents.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('onboarding.completed', 2)
                // The channel step is now the first incomplete one, so it is
                // the one that gets the button.
                ->where('onboarding.steps.0.done', true)
                ->where('onboarding.steps.1.done', true)
                ->where('onboarding.steps.2.key', 'channel')
                ->where('onboarding.steps.2.done', false));
    }

    public function test_step_state_matches_the_accounts_data(): void
    {
        $user = $this->newcomer();
        $agent = Agent::factory()->for($user)->create();
        KnowledgeSource::factory()->for($agent)->create();
        Channel::factory()->for($agent)->create();

        $checklist = app(OnboardingService::class)->forUser($user->fresh(), fromRegistration: true);

        $this->assertSame([true, true, true, false], array_column($checklist['steps'], 'done'));
        $this->assertSame(3, $checklist['completed']);
        $this->assertSame(75, $checklist['percent']);
        $this->assertFalse($checklist['complete']);
    }

    /**
     * Another owner's agents must not tick this owner's checklist.
     */
    public function test_progress_is_scoped_to_the_owner(): void
    {
        $user = $this->newcomer();
        $stranger = User::factory()->create();
        $strangersAgent = Agent::factory()->for($stranger)->create();
        KnowledgeSource::factory()->for($strangersAgent)->create();
        Channel::factory()->for($strangersAgent)->create();

        $checklist = app(OnboardingService::class)->forUser($user, fromRegistration: true);

        $this->assertSame(0, $checklist['completed']);
    }

    public function test_sending_a_sandbox_message_completes_the_last_step(): void
    {
        $this->stubLlm();

        $user = $this->newcomer();
        $agent = Agent::factory()->for($user)->create();

        $this->assertNull($user->playground_tried_at);

        $this->actingAs($user)
            ->postJson(route('agents.playground.messages.store', $agent), ['message' => 'Здравствуйте'])
            ->assertOk();

        $user = $user->fresh();
        $this->assertNotNull($user->playground_tried_at);
        $this->assertTrue(app(OnboardingService::class)->forUser($user, fromRegistration: true)['steps'][3]['done']);
    }

    public function test_finishing_every_step_shows_the_congratulations_once(): void
    {
        $user = $this->newcomer();
        $agent = Agent::factory()->for($user)->create();
        KnowledgeSource::factory()->for($agent)->create();
        Channel::factory()->for($agent)->create();
        $user->markPlaygroundTried();

        $this->actingAsNewRegistration($user)->get(route('agents.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('onboarding.celebrate', true)
                ->where('onboarding.visible', true));

        $this->assertNotNull($user->fresh()->onboarding_completed_at);

        // Spent — the card is gone from here on rather than congratulating the
        // owner on every visit to their own dashboard. `visible` false is what
        // removes it entirely, celebration and checklist alike.
        $this->actingAsNewRegistration($user->fresh())->get(route('agents.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('onboarding.celebrate', false)
                ->where('onboarding.visible', false));
    }

    /**
     * A session that never showed the checklist must not silently spend the
     * congratulations panel a registration session would have shown.
     */
    public function test_a_login_session_does_not_consume_the_congratulations(): void
    {
        $user = $this->newcomer();
        $agent = Agent::factory()->for($user)->create();
        KnowledgeSource::factory()->for($agent)->create();
        Channel::factory()->for($agent)->create();
        $user->markPlaygroundTried();

        $this->actingAs($user)->get(route('agents.index'))->assertOk();

        $this->assertNull($user->fresh()->onboarding_completed_at);
    }

    // --------------------------------------------------------------- dismissal

    public function test_the_checklist_can_be_skipped_and_stays_skipped(): void
    {
        $user = $this->newcomer();

        $this->actingAsNewRegistration($user)->deleteJson(route('onboarding.dismiss'))
            ->assertOk()
            ->assertJson(['dismissed' => true]);

        $this->assertNotNull($user->fresh()->onboarding_dismissed_at);

        $this->actingAsNewRegistration($user->fresh())->get(route('agents.index'))
            ->assertOk()
            ->assertDontSee(__('onboarding.checklist.title'), false);
    }

    /**
     * Skipping beats everything else — later progress must not resurrect a
     * panel the owner explicitly sent away.
     */
    public function test_skipping_survives_later_progress(): void
    {
        $user = $this->newcomer();
        $user->dismissOnboarding();

        $agent = Agent::factory()->for($user)->create();
        KnowledgeSource::factory()->for($agent)->create();

        $this->assertFalse(app(OnboardingService::class)->forUser($user->fresh(), fromRegistration: true)['visible']);
    }

    public function test_a_guest_cannot_dismiss_onboarding(): void
    {
        $this->deleteJson(route('onboarding.dismiss'))->assertUnauthorized();
    }

    // ------------------------------------------- registration-only gating

    /**
     * The headline rule, end to end through the real endpoint: registering
     * gives you the welcome AND the checklist — the welcome being the banner
     * the checklist carries, which is also the whole of it (the modal that
     * used to greet here would be a second greeting for the same arrival).
     */
    public function test_registering_shows_the_welcome_and_the_checklist(): void
    {
        $this->post('/register', [
            'name' => 'Nadia',
            'email' => 'nadia@example.test',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ])->assertRedirect(route('home'));

        $this->assertTrue(session(AuthController::ONBOARDING_SESSION_KEY));

        $this->get(route('agents.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('banner', 'Nadia')
                ->where('onboarding.visible', true)
                // ...and never a second greeting, nor the returning-user one.
                ->where('welcome', null)
                ->where('flash.greeting', null));
    }

    /**
     * The other half, end to end: logging in gives you the greeting and
     * NOTHING else.
     */
    public function test_logging_in_shows_only_the_greeting_and_no_checklist(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.test', 'name' => 'Ada']);

        $this->post('/login', ['email' => 'ada@example.test', 'password' => 'password'])
            ->assertRedirect(route('home'));

        $this->assertFalse(session()->has(AuthController::ONBOARDING_SESSION_KEY));

        $this->get(route('agents.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('flash.greeting', __('onboarding.back', ['name' => 'Ada']))
                ->where('onboarding.visible', false)
                ->where('welcome', null));
    }

    /**
     * The rule is "whatever their progress" — including no progress at all,
     * which is the case that most looks like it deserves onboarding.
     */
    public function test_a_returning_user_with_zero_progress_still_sees_no_checklist(): void
    {
        $user = User::factory()->newcomer()->create(['welcomed_at' => now()]);

        $this->actingAs($user)->get(route('agents.index'))
            ->assertOk()
            ->assertDontSee(__('onboarding.checklist.title'), false)
            // Not steps.agent.cta: in Russian that is "Создать агента", the
            // same words as the page's own create button, so it is present
            // whether or not the checklist rendered. The progress counter has
            // no such twin.
            ->assertDontSee(__('onboarding.checklist.progress', ['done' => 0, 'total' => 4]), false)
            ->assertDontSee('id="onboarding"', false);
    }

    /**
     * Registering, then signing in again later, is the sequence the rule is
     * really about: the checklist must not follow the account around.
     */
    public function test_the_checklist_does_not_come_back_on_a_later_login(): void
    {
        $this->post('/register', [
            'name' => 'Nadia',
            'email' => 'nadia@example.test',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ])->assertRedirect(route('home'));

        $this->get(route('agents.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('onboarding.visible', true));

        $this->post(route('logout'))->assertRedirect('/');

        $this->post('/login', ['email' => 'nadia@example.test', 'password' => 'secret-password'])
            ->assertRedirect(route('home'));

        $this->get(route('agents.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('onboarding.visible', false)
                // The welcome is spent too — it was shown during registration.
                ->where('welcome', null)
                ->where('flash.greeting', __('onboarding.back', ['name' => 'Nadia'])));
    }

    /**
     * Both greetings are name-bearing and distinct from one another, in every
     * locale — a swap between them would otherwise be invisible in tests that
     * only assert one of the two.
     */
    public function test_the_two_greetings_are_distinct_and_named_in_every_locale(): void
    {
        foreach (['en', 'ru', 'tg'] as $locale) {
            $welcome = trans('onboarding.welcome.title', ['name' => 'Nadia'], $locale);
            $back = trans('onboarding.back', ['name' => 'Nadia'], $locale);

            $this->assertStringContainsString('Nadia', $welcome, "[$locale] welcome must name the user");
            $this->assertStringContainsString('Nadia', $back, "[$locale] welcome-back must name the user");
            $this->assertNotSame($welcome, $back, "[$locale] the two greetings must differ");
        }

        // The exact wording that was asked for, pinned so a later edit cannot
        // quietly turn one into the other.
        $this->assertSame('Добро пожаловать, Nadia 🎉', trans('onboarding.welcome.title', ['name' => 'Nadia'], 'ru'));
        $this->assertSame('С возвращением, Nadia', trans('onboarding.back', ['name' => 'Nadia'], 'ru'));
        $this->assertSame('Welcome, Nadia 🎉', trans('onboarding.welcome.title', ['name' => 'Nadia'], 'en'));
        $this->assertSame('Welcome back, Nadia', trans('onboarding.back', ['name' => 'Nadia'], 'en'));
    }
}
