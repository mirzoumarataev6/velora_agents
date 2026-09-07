<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RememberMeTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        // The factory seeds a random remember_token; null it so the assertions
        // below observe only what the login request itself wrote.
        return User::factory()->create([
            'email' => 'owner@example.com',
            'password' => Hash::make('password123'),
            'remember_token' => null,
        ]);
    }

    /**
     * The recaller is what actually survives the browser being closed — the
     * session cookie does not. Its name is guard-derived, so ask the guard
     * rather than hardcoding the hash.
     */
    private function recallerName(): string
    {
        return Auth::guard('web')->getRecallerName();
    }

    public function test_checkbox_posts_remember_and_issues_a_long_lived_cookie(): void
    {
        $user = $this->user();

        $response = $this->post('/login', [
            'email' => 'owner@example.com',
            'password' => 'password123',
            'remember' => '1',
        ]);

        $response->assertRedirect(route('home'));
        $this->assertAuthenticatedAs($user);

        $recaller = collect($response->headers->getCookies())
            ->firstWhere(fn ($cookie) => $cookie->getName() === $this->recallerName());

        $this->assertNotNull($recaller, 'Expected a remember-me recaller cookie to be set.');

        // Laravel issues the recaller as a "forever" cookie (5 years), which
        // comfortably covers the ~30 days of persistence being asked for here.
        $this->assertGreaterThan(
            now()->addDays(30)->getTimestamp(),
            $recaller->getExpiresTime(),
            'Recaller cookie must outlive a 30-day window.'
        );

        // The cookie is only usable if the matching token was persisted.
        $this->assertNotEmpty($user->fresh()->remember_token);
    }

    public function test_unchecked_box_does_not_issue_a_recaller(): void
    {
        $user = $this->user();

        // An unchecked checkbox is simply absent from a real browser submission;
        // remember=0 covers the case where a client sends it explicitly.
        $response = $this->post('/login', [
            'email' => 'owner@example.com',
            'password' => 'password123',
            'remember' => '0',
        ]);

        $response->assertRedirect(route('home'));
        $this->assertAuthenticatedAs($user);

        $recaller = collect($response->headers->getCookies())
            ->firstWhere(fn ($cookie) => $cookie->getName() === $this->recallerName());

        $this->assertNull($recaller, 'No recaller cookie should be issued when remember is off.');
        $this->assertNull($user->fresh()->remember_token);
    }

    public function test_omitted_checkbox_does_not_issue_a_recaller(): void
    {
        $this->user();

        $response = $this->post('/login', [
            'email' => 'owner@example.com',
            'password' => 'password123',
        ]);

        $recaller = collect($response->headers->getCookies())
            ->firstWhere(fn ($cookie) => $cookie->getName() === $this->recallerName());

        $this->assertNull($recaller);
    }

    /**
     * The end-to-end guarantee: with only the recaller cookie (session gone,
     * as after closing the browser), a request to a protected page still
     * authenticates.
     */
    public function test_recaller_alone_restores_the_session_after_the_browser_closes(): void
    {
        $user = $this->user();

        $login = $this->post('/login', [
            'email' => 'owner@example.com',
            'password' => 'password123',
            'remember' => '1',
        ]);

        $recaller = collect($login->headers->getCookies())
            ->firstWhere(fn ($cookie) => $cookie->getName() === $this->recallerName());

        $this->assertNotNull($recaller);

        // Drop every cookie and the session, keeping only the recaller.
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        $response = $this->withUnencryptedCookie($this->recallerName(), $recaller->getValue())
            ->get('/home');

        // /home is the panel's front door and now opens on the dashboard; what
        // this test is about is that the recaller alone got them through it.
        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }
}
