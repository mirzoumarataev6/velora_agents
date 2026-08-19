<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create([
            'name' => 'Anvar Karimov',
            'email' => 'anvar@example.com',
        ]);
    }

    public function test_the_settings_page_renders_for_a_signed_in_user(): void
    {
        $this->actingAs($this->user)
            ->get('/settings')
            ->assertOk()
            ->assertSee('anvar@example.com');
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/settings')->assertRedirect();
    }

    public function test_name_and_email_changes_persist(): void
    {
        $this->actingAs($this->user)
            ->patch('/settings/profile', [
                'name' => 'Anvar K.',
                'email' => 'new@example.com',
            ])
            ->assertRedirect();

        $this->user->refresh();

        $this->assertSame('Anvar K.', $this->user->name);
        $this->assertSame('new@example.com', $this->user->email);
    }

    /**
     * The old verification stamp vouched for the old address. Carrying it over
     * would silently mark an unproven address as confirmed.
     */
    public function test_changing_the_email_clears_the_verified_stamp(): void
    {
        $this->user->forceFill(['email_verified_at' => now()])->save();

        $this->actingAs($this->user)->patch('/settings/profile', [
            'name' => $this->user->name,
            'email' => 'moved@example.com',
        ]);

        $this->assertNull($this->user->refresh()->email_verified_at);
    }

    public function test_keeping_the_same_email_keeps_the_verified_stamp(): void
    {
        $this->user->forceFill(['email_verified_at' => now()])->save();

        $this->actingAs($this->user)->patch('/settings/profile', [
            'name' => 'Renamed Only',
            'email' => 'anvar@example.com',
        ]);

        $this->assertNotNull($this->user->refresh()->email_verified_at);
        $this->assertSame('Renamed Only', $this->user->name);
    }

    public function test_an_email_already_taken_by_someone_else_is_rejected(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->actingAs($this->user)
            ->patch('/settings/profile', [
                'name' => 'Anvar',
                'email' => 'taken@example.com',
            ])
            ->assertSessionHasErrors('email', errorBag: 'profile');

        $this->assertSame('anvar@example.com', $this->user->refresh()->email);
    }

    public function test_the_users_own_email_does_not_count_as_taken(): void
    {
        $this->actingAs($this->user)
            ->patch('/settings/profile', [
                'name' => 'Still Anvar',
                'email' => 'anvar@example.com',
            ])
            ->assertSessionHasNoErrors();
    }

    public function test_name_and_email_are_validated(): void
    {
        $this->actingAs($this->user)
            ->patch('/settings/profile', ['name' => '', 'email' => 'not-an-email'])
            ->assertSessionHasErrors(['name', 'email'], errorBag: 'profile');
    }

    /**
     * One user's settings request must never reach another user's row — there is
     * no id in the route, so the signed-in user is the only thing it can touch.
     */
    public function test_a_profile_update_only_ever_touches_the_signed_in_user(): void
    {
        $other = User::factory()->create(['name' => 'Untouched', 'email' => 'other@example.com']);

        $this->actingAs($this->user)->patch('/settings/profile', [
            'name' => 'Changed',
            'email' => 'changed@example.com',
        ]);

        $other->refresh();
        $this->assertSame('Untouched', $other->name);
        $this->assertSame('other@example.com', $other->email);
    }

    public function test_initials_come_from_the_name_and_fall_back_to_the_email(): void
    {
        $this->assertSame('AK', $this->user->initials());

        $single = User::factory()->create(['name' => 'Madina']);
        $this->assertSame('M', $single->initials());

        $nameless = User::factory()->create(['name' => '', 'email' => 'zed@example.com']);
        $this->assertSame('Z', $nameless->initials());
    }
}
