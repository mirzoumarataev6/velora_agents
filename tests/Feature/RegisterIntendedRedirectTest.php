<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AuthController::register() now honors `url.intended`, the same way
 * login() already does — needed so a brand-new user following an invite
 * link (Stage 3b) lands back on it after signing up, instead of on the
 * dashboard having accepted nothing. Laravel's own `redirect()->guest()`
 * (fired automatically for any protected route a guest hits) is what stores
 * `url.intended`; this test proves register() now reads it back.
 */
class RegisterIntendedRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_registering_after_being_bounced_from_a_protected_page_returns_there(): void
    {
        // Hitting a protected route as a guest stores url.intended via
        // Laravel's own redirect()->guest() — the same mechanism an
        // invitation accept link (Stage 3b) relies on.
        $this->get(route('settings.index'));

        $response = $this->post('/register', [
            'name' => 'Azim',
            'email' => 'azim@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertRedirect(route('settings.index'));
    }

    public function test_a_plain_registration_still_lands_on_the_dashboard(): void
    {
        $response = $this->post('/register', [
            'name' => 'Azim',
            'email' => 'azim@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertRedirect(route('dashboard'));
    }
}
