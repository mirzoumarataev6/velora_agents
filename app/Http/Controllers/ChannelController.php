<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\Channel;
use App\Services\Channels\ChannelDriverManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

class ChannelController extends Controller
{
    public function connectTelegram(Request $request, Agent $agent, ChannelDriverManager $drivers): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $agent);

        $request->validate([
            'bot_token' => ['required', 'string', 'regex:/^\d+:[A-Za-z0-9_-]+$/'],
        ], [
            'bot_token.regex' => 'Токен бота выглядит некорректно. Скопируйте его из чата с @BotFather.',
        ]);

        $channel = $agent->channels()->firstOrNew(['type' => 'telegram']);
        $channel->credentials = ['bot_token' => $request->string('bot_token')->toString()];
        $channel->status = 'error'; // corrected to "connected" below only on success
        $channel->save();

        try {
            $info = $drivers->for($channel)->connect($channel);

            $channel->settings = array_merge($channel->settings ?? [], [
                'bot_username' => $info['bot_username'],
                'bot_first_name' => $info['bot_first_name'] ?? null,
                'webhook_secret' => $info['webhook_secret'],
                'webhook_url' => $info['webhook_url'],
            ]);
            $channel->status = 'connected';
            $channel->save();

            if ($request->wantsJson()) {
                return response()->json(['channel' => $this->serialize($channel)]);
            }

            return back()->with('status', "Telegram-бот @{$info['bot_username']} подключён.");
        } catch (Throwable $e) {
            $channel->save();
            $message = 'Не удалось подключить бота: '.$e->getMessage();

            if ($request->wantsJson()) {
                return response()->json([
                    'message' => $message,
                    'errors' => ['bot_token' => [$message]],
                ], 422);
            }

            return back()->withErrors(['bot_token' => $message]);
        }
    }

    public function disconnectTelegram(Request $request, Agent $agent, Channel $channel, ChannelDriverManager $drivers): RedirectResponse|JsonResponse
    {
        $this->authorize('delete', $channel);

        try {
            $drivers->for($channel)->disconnect($channel);
        } catch (Throwable) {
            // Best-effort: still disconnect locally even if Telegram's deleteWebhook call fails.
        }

        $channel->update([
            'status' => 'disconnected',
            'credentials' => null,
            'settings' => null,
        ]);

        if ($request->wantsJson()) {
            return response()->json(['channel' => $this->serialize($channel)]);
        }

        return back()->with('status', 'Telegram отключён.');
    }

    public function connectWhatsApp(Request $request, Agent $agent, ChannelDriverManager $drivers): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $agent);

        $data = $request->validate([
            'phone_number_id' => ['required', 'string'],
            'access_token' => ['required', 'string'],
            'verify_token' => ['required', 'string', 'min:8'],
        ], [], [
            'phone_number_id' => 'Phone Number ID',
            'access_token' => 'токен доступа',
            'verify_token' => 'токен верификации',
        ]);

        $channel = $agent->channels()->firstOrNew(['type' => 'whatsapp']);
        $channel->credentials = $data;
        $channel->status = 'error'; // corrected to "connected" below only on success
        $channel->save();

        try {
            $info = $drivers->for($channel)->connect($channel);

            $channel->settings = array_merge($channel->settings ?? [], [
                'display_phone_number' => $info['display_phone_number'] ?? null,
                'verified_name' => $info['verified_name'] ?? null,
                'webhook_url' => $info['webhook_url'],
            ]);
            $channel->status = 'connected';
            $channel->save();

            if ($request->wantsJson()) {
                return response()->json(['channel' => $this->serialize($channel)]);
            }

            return back()->with('status', 'WhatsApp подключён. Завершите настройку в Meta App Dashboard.');
        } catch (Throwable $e) {
            $channel->save();
            $message = 'Не удалось подключить WhatsApp: '.$e->getMessage();

            if ($request->wantsJson()) {
                return response()->json([
                    'message' => $message,
                    'errors' => ['access_token' => [$message]],
                ], 422);
            }

            return back()->withErrors(['access_token' => $message]);
        }
    }

    public function disconnectWhatsApp(Request $request, Agent $agent, Channel $channel, ChannelDriverManager $drivers): RedirectResponse|JsonResponse
    {
        $this->authorize('delete', $channel);

        try {
            $drivers->for($channel)->disconnect($channel);
        } catch (Throwable) {
            // Best-effort: still disconnect locally even if the API call fails.
        }

        $channel->update([
            'status' => 'disconnected',
            'credentials' => null,
            'settings' => null,
        ]);

        if ($request->wantsJson()) {
            return response()->json(['channel' => $this->serialize($channel)]);
        }

        return back()->with('status', 'WhatsApp отключён.');
    }

    public function connectInstagram(Request $request, Agent $agent, ChannelDriverManager $drivers): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $agent);

        $data = $request->validate([
            'ig_business_account_id' => ['required', 'string'],
            'page_access_token' => ['required', 'string'],
            'verify_token' => ['required', 'string', 'min:8'],
        ], [], [
            'ig_business_account_id' => 'ID бизнес-аккаунта Instagram',
            'page_access_token' => 'токен доступа страницы',
            'verify_token' => 'токен верификации',
        ]);

        $channel = $agent->channels()->firstOrNew(['type' => 'instagram']);
        $channel->credentials = $data;
        $channel->status = 'error'; // corrected to "connected" below only on success
        $channel->save();

        try {
            $info = $drivers->for($channel)->connect($channel);

            $channel->settings = array_merge($channel->settings ?? [], [
                'ig_username' => $info['ig_username'] ?? null,
                'ig_name' => $info['ig_name'] ?? null,
                'webhook_url' => $info['webhook_url'],
            ]);
            $channel->status = 'connected';
            $channel->save();

            if ($request->wantsJson()) {
                return response()->json(['channel' => $this->serialize($channel)]);
            }

            return back()->with('status', 'Instagram подключён. Завершите настройку в Meta App Dashboard.');
        } catch (Throwable $e) {
            $channel->save();
            $message = 'Не удалось подключить Instagram: '.$e->getMessage();

            if ($request->wantsJson()) {
                return response()->json([
                    'message' => $message,
                    'errors' => ['page_access_token' => [$message]],
                ], 422);
            }

            return back()->withErrors(['page_access_token' => $message]);
        }
    }

    public function disconnectInstagram(Request $request, Agent $agent, Channel $channel, ChannelDriverManager $drivers): RedirectResponse|JsonResponse
    {
        $this->authorize('delete', $channel);

        try {
            $drivers->for($channel)->disconnect($channel);
        } catch (Throwable) {
            // Best-effort: still disconnect locally even if the API call fails.
        }

        $channel->update([
            'status' => 'disconnected',
            'credentials' => null,
            'settings' => null,
        ]);

        if ($request->wantsJson()) {
            return response()->json(['channel' => $this->serialize($channel)]);
        }

        return back()->with('status', 'Instagram отключён.');
    }

    protected function serialize(Channel $channel): array
    {
        return [
            'id' => $channel->id,
            'type' => $channel->type,
            'status' => $channel->status,
            'bot_username' => $channel->settings['bot_username'] ?? null,
            'display_phone_number' => $channel->settings['display_phone_number'] ?? null,
            'verified_name' => $channel->settings['verified_name'] ?? null,
            'ig_username' => $channel->settings['ig_username'] ?? null,
            'ig_name' => $channel->settings['ig_name'] ?? null,
            'webhook_url' => $channel->settings['webhook_url'] ?? null,
            'verify_token' => $channel->credentials['verify_token'] ?? null,
        ];
    }
}
