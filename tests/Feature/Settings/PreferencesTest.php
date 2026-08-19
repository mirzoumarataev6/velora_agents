<?php

namespace Tests\Feature\Settings;

use App\Jobs\SendLeadNotificationJob;
use App\Models\Agent;
use App\Models\Channel;
use App\Models\Lead;
use App\Models\User;
use App\Services\Channels\ChannelDriverInterface;
use App\Services\Channels\ChannelDriverManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class PreferencesTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['locale' => 'ru', 'theme' => null]);
    }

    protected function preferences(array $overrides = []): array
    {
        return array_merge([
            'locale' => 'ru',
            'theme' => 'system',
            'lead_notifications_enabled' => '1',
        ], $overrides);
    }

    public function test_preferences_persist(): void
    {
        $this->actingAs($this->user)
            ->patch('/settings/preferences', $this->preferences([
                'locale' => 'en',
                'theme' => 'dark',
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->user->refresh();

        $this->assertSame('en', $this->user->locale);
        $this->assertSame('dark', $this->user->theme);
        $this->assertTrue($this->user->lead_notifications_enabled);
    }

    /**
     * "Follow the system" is the absence of a preference, so it is stored as
     * null rather than as a third value every reader would have to special-case.
     */
    public function test_the_system_theme_is_stored_as_no_preference(): void
    {
        $this->user->forceFill(['theme' => 'dark'])->save();

        $this->actingAs($this->user)->patch('/settings/preferences', $this->preferences());

        $this->assertNull($this->user->refresh()->theme);
    }

    public function test_the_locale_choice_also_lands_in_the_session(): void
    {
        $this->actingAs($this->user)
            ->patch('/settings/preferences', $this->preferences(['locale' => 'tg']))
            ->assertSessionHas('locale', 'tg');

        $this->assertSame('tg', $this->user->refresh()->locale);
    }

    public function test_an_unsupported_locale_or_theme_is_rejected(): void
    {
        $this->actingAs($this->user)
            ->patch('/settings/preferences', $this->preferences(['locale' => 'fr']))
            ->assertSessionHasErrors('locale', errorBag: 'preferences');

        $this->actingAs($this->user)
            ->patch('/settings/preferences', $this->preferences(['theme' => 'neon']))
            ->assertSessionHasErrors('theme', errorBag: 'preferences');
    }

    public function test_an_unchecked_notification_box_turns_alerts_off(): void
    {
        $this->assertTrue($this->user->lead_notifications_enabled);

        // An unchecked checkbox is simply absent from the submission.
        $prefs = $this->preferences();
        unset($prefs['lead_notifications_enabled']);

        $this->actingAs($this->user)->patch('/settings/preferences', $prefs);

        $this->assertFalse($this->user->refresh()->lead_notifications_enabled);
    }

    public function test_the_theme_toggle_endpoint_persists_to_the_account(): void
    {
        $this->actingAs($this->user)
            ->putJson('/settings/theme', ['theme' => 'dark'])
            ->assertOk()
            ->assertJson(['theme' => 'dark']);

        $this->assertSame('dark', $this->user->refresh()->theme);

        $this->actingAs($this->user)
            ->putJson('/settings/theme', ['theme' => 'system'])
            ->assertOk();

        $this->assertNull($this->user->refresh()->theme);
    }

    public function test_the_theme_endpoint_rejects_an_unknown_theme(): void
    {
        $this->actingAs($this->user)
            ->putJson('/settings/theme', ['theme' => 'sepia'])
            ->assertStatus(422);
    }

    /**
     * The toggle has to be more than decorative: with alerts off, the job that
     * would have messaged the owner must not send anything.
     */
    public function test_muting_lead_alerts_stops_the_telegram_notification(): void
    {
        $agent = Agent::factory()->for($this->user)->create([
            'notify_telegram_chat_id' => '555',
            'notify_telegram_linked_at' => now(),
        ]);
        Channel::factory()->for($agent)->create(['type' => 'telegram', 'status' => 'connected']);
        $lead = Lead::factory()->for($agent)->create();

        $this->user->forceFill(['lead_notifications_enabled' => false])->save();

        $driver = Mockery::mock(ChannelDriverInterface::class);
        $driver->shouldNotReceive('sendMessage');

        $manager = Mockery::mock(ChannelDriverManager::class);
        $manager->shouldReceive('for')->andReturn($driver);

        (new SendLeadNotificationJob($lead->fresh()))->handle($manager);
    }

    public function test_lead_alerts_are_delivered_while_they_are_switched_on(): void
    {
        $agent = Agent::factory()->for($this->user)->create([
            'notify_telegram_chat_id' => '555',
            'notify_telegram_linked_at' => now(),
        ]);
        Channel::factory()->for($agent)->create(['type' => 'telegram', 'status' => 'connected']);
        $lead = Lead::factory()->for($agent)->create();

        $driver = Mockery::mock(ChannelDriverInterface::class);
        $driver->shouldReceive('sendMessage')->once();

        $manager = Mockery::mock(ChannelDriverManager::class);
        $manager->shouldReceive('for')->andReturn($driver);

        (new SendLeadNotificationJob($lead->fresh()))->handle($manager);
    }

    public function test_guests_cannot_change_preferences(): void
    {
        $this->patch('/settings/preferences', $this->preferences())->assertRedirect();
        $this->putJson('/settings/theme', ['theme' => 'dark'])->assertUnauthorized();
    }
}
