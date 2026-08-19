<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
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

    public function test_reset_password_page_renders_with_a_token(): void
    {
        $response = $this->get('/ru/reset-password/some-token?email=owner@example.com');
        $response->assertOk();
        $response->assertSee('some-token', false);
        $response->assertSee('owner@example.com', false);
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
