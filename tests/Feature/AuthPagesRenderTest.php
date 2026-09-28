<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class AuthPagesRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_auth_pages_render_on_the_shared_centered_layout(): void
    {
        foreach (['login', 'register', 'forgot-password'] as $page) {
            $response = $this->get("/ru/{$page}");
            $response->assertOk();
            // The slim top bar and single-column card are the shared layout's
            // signature; the old two-column grid + aurora panel are gone.
            $response->assertDontSee('aurora-panel');
            $response->assertDontSee('lg:grid-cols-2', false);
        }
    }

    /**
     * The page must hand the form the REAL token.
     *
     * This used to assert that "some-token" appeared anywhere in the HTML, and
     * it passed for the wrong reason: the locale switcher builds an href for
     * every language by swapping the locale segment of the current path, so
     * "/en/reset-password/some-token" put the string on the page no matter what
     * the form actually carried. Meanwhile the hidden token field held "ru" —
     * see PasswordResetController::reset — and every reset from an emailed link
     * failed.
     *
     * Asserting the prop by name is what makes that impossible to repeat.
     */
    public function test_reset_password_page_hands_the_form_the_real_token(): void
    {
        $this->get('/ru/reset-password/some-token?email=owner@example.com')
            ->assertOk()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->component('Auth/ResetPassword')
                    ->where('token', 'some-token')
                    ->where('email', 'owner@example.com')
            );
    }

    /**
     * End to end, with a token the broker actually issued: open the page the
     * emailed link points at, post back exactly what it carried, and check the
     * password really changed.
     *
     * The test below this one posts a token it made itself, which is why it
     * never noticed the page was serving a different one. This one can only
     * pass if the page and the broker agree.
     */
    public function test_the_token_the_page_carries_is_the_one_that_resets_the_password(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.com']);
        $token = Password::createToken($user);

        $carried = null;

        $this->get("/ru/reset-password/{$token}?email=owner@example.com")
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$carried) {
                $carried = $page->toArray()['props']['token'];
            });

        $this->post('/reset-password', [
            'token' => $carried,
            'email' => 'owner@example.com',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('new-password-123', $user->fresh()->password));
    }

    public function test_forgot_password_reports_success_without_leaking_account_existence(): void
    {
        // No such user — the response must still look like success.
        $response = $this->from('/ru/forgot-password')->post('/forgot-password', [
            'email' => 'nobody@example.com',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status');
        $response->assertSessionHasNoErrors();
    }

    public function test_reset_password_updates_the_password_with_a_valid_token(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.com']);
        $token = Password::createToken($user);

        $response = $this->post('/reset-password', [
            'token' => $token,
            'email' => 'owner@example.com',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('status');
        $this->assertTrue(Hash::check('new-password-123', $user->fresh()->password));
    }
}
