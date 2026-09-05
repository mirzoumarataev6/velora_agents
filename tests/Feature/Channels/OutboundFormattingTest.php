<?php

namespace Tests\Feature\Channels;

use App\Models\Channel;
use App\Services\Channels\InstagramDriver;
use App\Services\Channels\TelegramDriver;
use App\Services\Channels\WhatsAppDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * What each channel actually puts on the wire when the model writes Markdown.
 *
 * The reported bug is one line of the reply — "**OMEN 16** — 14999 сомони" —
 * reaching the customer with the asterisks visible. Every driver sent the
 * model's text verbatim, and no two of these platforms read the same dialect,
 * so each one needs its own assertion rather than one shared expectation.
 */
class OutboundFormattingTest extends TestCase
{
    use RefreshDatabase;

    protected const REPLY = "**OMEN 16** — 14999 сомони, это *лучший* вариант для игр.\n- **Macbook Pro 14** — 17999";

    // --- Telegram ------------------------------------------------------------

    public function test_telegram_sends_bold_as_html_with_a_parse_mode(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200)]);

        app(TelegramDriver::class)->sendMessage(Channel::factory()->create(), '555', self::REPLY);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'sendMessage')) {
                return false;
            }

            $this->assertSame('HTML', $request['parse_mode']);
            $this->assertStringContainsString('<b>OMEN 16</b>', $request['text']);
            $this->assertStringContainsString('<i>лучший</i>', $request['text']);
            $this->assertStringNotContainsString('**', $request['text']);

            return true;
        });
    }

    /**
     * Formatting is a nicety; delivery is not. If Telegram refuses the HTML for
     * any reason, the customer still gets the message.
     */
    public function test_telegram_falls_back_to_plain_text_when_the_html_is_refused(): void
    {
        Http::fake(['api.telegram.org/*' => Http::sequence()
            ->push(['ok' => false, 'description' => "Bad Request: can't parse entities"], 400)
            ->push(['ok' => true, 'result' => []], 200),
        ]);

        app(TelegramDriver::class)->sendMessage(Channel::factory()->create(), '555', self::REPLY);

        $sent = [];
        Http::assertSent(function ($request) use (&$sent) {
            if (str_contains($request->url(), 'sendMessage')) {
                $sent[] = ['text' => $request['text'], 'parse_mode' => $request['parse_mode'] ?? null];
            }

            return true;
        });

        $this->assertCount(2, $sent);

        // The retry carries no parse_mode and no markup at all.
        $this->assertNull($sent[1]['parse_mode']);
        $this->assertStringContainsString('OMEN 16', $sent[1]['text']);
        $this->assertStringNotContainsString('<b>', $sent[1]['text']);
        $this->assertStringNotContainsString('**', $sent[1]['text']);
    }

    // --- WhatsApp ------------------------------------------------------------

    public function test_whatsapp_converts_bold_to_its_own_single_asterisk(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]], 200)]);

        $channel = Channel::factory()->whatsapp()->create();

        // WhatsApp only allows a free-form message inside 24h of the customer's
        // own last one, so the conversation has to exist for anything to be sent.
        $conversation = $channel->agent->conversations()->create([
            'channel_id' => $channel->id,
            'external_user_id' => '992900000000',
            'status' => 'ai',
        ]);
        $conversation->messages()->create(['role' => 'user', 'content' => 'Салом']);

        app(WhatsAppDriver::class)->sendMessage($channel, '992900000000', self::REPLY);

        Http::assertSent(function ($request) {
            $body = $request['text']['body'] ?? '';

            $this->assertStringContainsString('*OMEN 16*', $body);
            // The markdown form would show as literal punctuation.
            $this->assertStringNotContainsString('**', $body);
            // And bold must not have been re-read as italic on the way out.
            $this->assertStringNotContainsString('_OMEN 16_', $body);
            $this->assertStringContainsString('_лучший_', $body);

            return true;
        });
    }

    // --- Instagram -----------------------------------------------------------

    public function test_instagram_strips_formatting_entirely(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['message_id' => 'm1'], 200)]);

        $channel = Channel::factory()->instagram()->create();

        app(InstagramDriver::class)->sendMessage($channel, '555', self::REPLY);

        Http::assertSent(function ($request) {
            $text = $request['message']['text'] ?? '';

            $this->assertStringContainsString('OMEN 16', $text);
            // Instagram DMs render nothing, so every marker has to be gone —
            // including the single asterisk that WhatsApp wants.
            foreach (['**', '*', '_', '<b>'] as $marker) {
                $this->assertStringNotContainsString($marker, $text);
            }

            return true;
        });
    }
}
