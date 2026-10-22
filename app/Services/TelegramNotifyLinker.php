<?php

namespace App\Services;

use App\Models\Agent;
use Illuminate\Support\Str;
use RuntimeException;

class TelegramNotifyLinker
{
    public const START_PREFIX = 'notify_';

    public const COMMAND_PATTERN = '/^\/start(?:@\S+)?\s+'.self::START_PREFIX.'([A-Za-z0-9]+)\s*$/';

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

    public function codeFromCommand(string $text): ?string
    {
        return preg_match(self::COMMAND_PATTERN, trim($text), $m) ? $m[1] : null;
    }

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
