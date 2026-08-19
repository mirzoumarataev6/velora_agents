<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SecuritySettingsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create([
            'password' => Hash::make('current-password'),
        ]);
    }

    public function test_a_password_change_persists(): void
    {
        $this->actingAs($this->user)
            ->put('/settings/password', [
                'current_password' => 'current-password',
                'password' => 'a-brand-new-one',
                'password_confirmation' => 'a-brand-new-one',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('a-brand-new-one', $this->user->refresh()->password));
    }

    /**
     * The whole point of asking for the current password: a browser someone
     * left signed in must not be usable to take the account over.
     */
    public function test_the_wrong_current_password_is_rejected(): void
    {
        $this->actingAs($this->user)
            ->put('/settings/password', [
                'current_password' => 'not-it',
                'password' => 'a-brand-new-one',
                'password_confirmation' => 'a-brand-new-one',
            ])
            ->assertSessionHasErrors('current_password', errorBag: 'password');

        $this->assertTrue(Hash::check('current-password', $this->user->refresh()->password));
    }

    public function test_the_new_password_must_be_confirmed_and_long_enough(): void
    {
        $this->actingAs($this->user)
            ->put('/settings/password', [
                'current_password' => 'current-password',
                'password' => 'short',
                'password_confirmation' => 'mismatch',
            ])
            ->assertSessionHasErrors('password', errorBag: 'password');

        $this->assertTrue(Hash::check('current-password', $this->user->refresh()->password));
    }

    public function test_the_new_password_cannot_be_the_current_one(): void
    {
        $this->actingAs($this->user)
            ->put('/settings/password', [
                'current_password' => 'current-password',
                'password' => 'current-password',
                'password_confirmation' => 'current-password',
            ])
            ->assertSessionHasErrors('password', errorBag: 'password');
    }

    /**
     * Changing the password invalidates sessions carrying the old hash — this
     * one included, unless it is re-stamped. Being logged out by your own
     * successful password change would read as a failure.
     */
    public function test_the_current_session_survives_its_own_password_change(): void
    {
        $this->actingAs($this->user)
            ->put('/settings/password', [
                'current_password' => 'current-password',
                'password' => 'a-brand-new-one',
                'password_confirmation' => 'a-brand-new-one',
            ]);

        $this->assertAuthenticatedAs($this->user->refresh());

        $this->get('/settings')->assertOk();
    }

    public function test_signing_out_other_devices_requires_the_password(): void
    {
        $this->actingAs($this->user)
            ->delete('/settings/sessions', ['password' => 'wrong'])
            ->assertSessionHasErrors('password', errorBag: 'sessions');
    }

    public function test_signing_out_other_devices_succeeds_with_the_right_password(): void
    {
        $before = $this->user->password;

        $this->actingAs($this->user)
            ->delete('/settings/sessions', ['password' => 'current-password'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        // The stored hash is cycled, which is what invalidates the sessions
        // still carrying the previous one.
        $this->assertNotSame($before, $this->user->refresh()->password);
        $this->assertTrue(Hash::check('current-password', $this->user->password));
        $this->assertAuthenticatedAs($this->user);
    }

    public function test_guests_cannot_reach_any_security_endpoint(): void
    {
        $this->put('/settings/password', [])->assertRedirect();
        $this->delete('/settings/sessions', [])->assertRedirect();
    }
}
