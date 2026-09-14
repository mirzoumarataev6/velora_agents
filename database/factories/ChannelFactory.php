<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\Channel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Channel>
 */
class ChannelFactory extends Factory
{
    public function definition(): array
    {
        return [
            'agent_id' => Agent::factory(),
            'type' => 'telegram',
            'status' => 'connected',
            'credentials' => ['bot_token' => '123456789:FAKE-TOKEN-FOR-TESTS'],
            'settings' => [
                'bot_username' => 'test_bot',
                'webhook_secret' => Str::random(48),
            ],
        ];
    }

    /**
     * Fixed rather than random so a test can sign a payload with it without
     * having to read the channel back first.
     */
    public const APP_SECRET = 'test-meta-app-secret-0123456789';

    public function whatsapp(): static
    {
        return $this->state(fn () => [
            'type' => 'whatsapp',
            'credentials' => [
                'phone_number_id' => '1234567890',
                'access_token' => 'FAKE-WHATSAPP-ACCESS-TOKEN',
                'verify_token' => Str::random(32),
                // Meta signs every webhook delivery with this; without it the
                // channel cannot verify a delivery and refuses them all.
                'app_secret' => self::APP_SECRET,
            ],
            'settings' => [
                'display_phone_number' => '+1 555-0100',
                'verified_name' => 'Test Business',
                'webhook_url' => 'https://example.test/webhooks/whatsapp/fake-uuid',
            ],
        ]);
    }

    public function instagram(): static
    {
        return $this->state(fn () => [
            'type' => 'instagram',
            'credentials' => [
                'ig_business_account_id' => '9876543210',
                'page_access_token' => 'FAKE-INSTAGRAM-PAGE-TOKEN',
                'verify_token' => Str::random(32),
                'app_secret' => self::APP_SECRET,
            ],
            'settings' => [
                'ig_username' => 'test_ig_business',
                'ig_name' => 'Test Business',
                'webhook_url' => 'https://example.test/webhooks/instagram/fake-uuid',
            ],
        ]);
    }
}
