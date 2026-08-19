<?php

namespace App\Services;

use App\Models\Agent;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Drives the "link my personal Telegram to get new-lead alerts" handshake.
 *
 * The flow reuses the agent's existing customer-facing Telegram bot:
 *   1. begin() mints a one-time code and returns a t.me deep link carrying it.
 *   2. The owner opens the link and taps Start, so Telegram delivers
 *      "/start notify_<code>" to that same bot's webhook.
 *   3. HandleIncomingMessageJob spots the command and calls complete(), storing
 *      the owner's chat id as the notification target.
 */
class TelegramNotifyLinker
{
    /** Deep-link start payloads are prefixed so the webhook can tell them apart. */
    public const START_PREFIX = 'notify_';

    /**
     * Matches "/start notify_<code>" (optionally "/start@Bot ...") and captures the code.
     */
    public const COMMAND_PATTERN = '/^\/start(?:@\S+)?\s+'.self::START_PREFIX.'([A-Za-z0-9]+)\s*$/';

    /**
     * Mint a fresh link code for the agent and build the deep link the owner
     * opens. Requires a connected Telegram bot to link through.
     *
     * @return array{deep_link: string, bot_username: string}
     */
    public function begin(Agent $agent): array
    {
        $channel = $agent->channels()
            ->where('type', 'telegram')
            ->where('status', 'connected')
            ->first();

        $botUsername = $channel?->settings['bot_username'] ?? null;

        if (! $botUsername) {
            throw new RuntimeException('telegram_not_connected');
        }

        $code = Str::random(24);
        $agent->forceFill(['notify_telegram_link_code' => $code])->save();

        return [
            'deep_link' => "https://t.me/{$botUsername}?start=".self::START_PREFIX.$code,
            'bot_username' => $botUsername,
        ];
    }

    /**
     * Extract the link code from a "/start notify_<code>" command text, or null
     * if the text is not that command.
     */
    public function codeFromCommand(string $text): ?string
    {
        return preg_match(self::COMMAND_PATTERN, trim($text), $m) ? $m[1] : null;
    }

    /**
     * Complete linking if $code matches the agent's outstanding link code.
     * Returns true on success (chat id stored, code consumed), false otherwise.
     */
    public function complete(Agent $agent, string $code, string $chatId, ?string $username): bool
    {
        if (empty($agent->notify_telegram_link_code)
            || ! hash_equals($agent->notify_telegram_link_code, $code)) {
            return false;
        }

        $agent->forceFill([
            'notify_telegram_chat_id' => $chatId,
            'notify_telegram_username' => $username,
            'notify_telegram_linked_at' => now(),
            'notify_telegram_link_code' => null,
        ])->save();

        return true;
    }

    public function unlink(Agent $agent): void
    {
        $agent->forceFill([
            'notify_telegram_chat_id' => null,
            'notify_telegram_username' => null,
            'notify_telegram_linked_at' => null,
            'notify_telegram_link_code' => null,
        ])->save();
    }
}
