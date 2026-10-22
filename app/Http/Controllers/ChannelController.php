<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\Channel;
use App\Services\Channels\ChannelDriverManager;
use App\Services\Channels\GreenApiDriver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

class ChannelController extends Controller
{
    public function connectTelegram(Request $request, Agent $agent, ChannelDriverManager $drivers): RedirectResponse|JsonResponse
    {
        $this->authorize('channels.manage', $agent);

        $request->validate([
            'bot_token' => ['required', 'string', 'regex:/^\d+:[A-Za-z0-9_-]+$/'],
        ], [
            'bot_token.regex' => 'Токен бота выглядит некорректно. Скопируйте его из чата с @BotFather.',
        ]);

        $channel = $agent->channels()->firstOrNew(['type' => 'telegram']);
        $channel->credentials = ['bot_token' => $request->string('bot_token')->toString()];
        $channel->status = 'error';
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
        $this->authorize('channels.manage', $agent);

        $data = $request->validate([
            'phone_number_id' => ['required', 'string'],
            'access_token' => ['required', 'string'],
            'verify_token' => ['required', 'string', 'min:8'],

            'app_secret' => ['required', 'string', 'min:16'],
        ], [], [
            'phone_number_id' => 'Phone Number ID',
            'access_token' => 'токен доступа',
            'verify_token' => 'токен верификации',
            'app_secret' => 'App Secret',
        ]);

        $channel = $agent->channels()->firstOrNew(['type' => 'whatsapp']);
        $channel->credentials = $data;
        $channel->status = 'error';
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
        $this->authorize('channels.manage', $agent);

        $data = $request->validate([
            'ig_business_account_id' => ['required', 'string'],
            'page_access_token' => ['required', 'string'],
            'verify_token' => ['required', 'string', 'min:8'],

            'app_secret' => ['required', 'string', 'min:16'],
        ], [], [
            'ig_business_account_id' => 'ID бизнес-аккаунта Instagram',
            'page_access_token' => 'токен доступа страницы',
            'verify_token' => 'токен верификации',
            'app_secret' => 'App Secret',
        ]);

        $channel = $agent->channels()->firstOrNew(['type' => 'instagram']);
        $channel->credentials = $data;
        $channel->status = 'error';
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

    public function connectGreenApi(Request $request, Agent $agent, ChannelDriverManager $drivers): RedirectResponse|JsonResponse
    {
        $this->authorize('channels.manage', $agent);

        $data = $request->validate([
            'id_instance' => ['required', 'string', 'regex:/^\d{6,20}$/'],
            'api_token_instance' => ['required', 'string', 'regex:/^[A-Za-z0-9]{20,}$/'],

            'api_url' => ['nullable', 'url:https'],
        ], [], [
            'id_instance' => 'idInstance',
            'api_token_instance' => 'apiTokenInstance',
            'api_url' => 'apiUrl',
        ]);

        $channel = $agent->channels()->firstOrNew(['type' => 'greenapi']);
        $channel->credentials = [
            'id_instance' => $data['id_instance'],
            'api_token_instance' => $data['api_token_instance'],
            'api_url' => rtrim(($data['api_url'] ?? null) ?: GreenApiDriver::DEFAULT_API_URL, '/'),
        ];
        $channel->status = 'error';
        $channel->save();

        try {
            $info = $drivers->for($channel)->connect($channel);

            $channel->credentials = array_merge($channel->credentials, ['webhook_token' => $info['webhook_token']]);
            $channel->settings = array_merge($channel->settings ?? [], [
                'state_instance' => $info['state_instance'] ?? null,
                'webhook_url' => $info['webhook_url'],
                'webhook_registered' => $info['webhook_registered'],
                'webhook_error' => $info['webhook_error'],
            ]);
            $channel->status = 'connected';
            $channel->save();

            if ($request->wantsJson()) {
                return response()->json(['channel' => $this->serialize($channel)]);
            }

            return back()->with('status', $info['webhook_registered']
                ? 'WhatsApp (Green API) подключён, вебхук настроен автоматически.'
                : 'WhatsApp (Green API) подключён, но вебхук не удалось настроить автоматически — укажите URL в консоли Green API.');
        } catch (Throwable $e) {
            $channel->save();
            $message = 'Не удалось подключить Green API: '.$e->getMessage();

            if ($request->wantsJson()) {
                return response()->json([
                    'message' => $message,
                    'errors' => ['api_token_instance' => [$message]],
                ], 422);
            }

            return back()->withErrors(['api_token_instance' => $message]);
        }
    }

    public function disconnectGreenApi(Request $request, Agent $agent, Channel $channel, ChannelDriverManager $drivers): RedirectResponse|JsonResponse
    {
        $this->authorize('delete', $channel);

        try {
            $drivers->for($channel)->disconnect($channel);
        } catch (Throwable) {

        }

        $channel->update([
            'status' => 'disconnected',
            'credentials' => null,
            'settings' => null,
        ]);

        if ($request->wantsJson()) {
            return response()->json(['channel' => $this->serialize($channel)]);
        }

        return back()->with('status', 'WhatsApp (Green API) отключён.');
    }

    public function connectInstagrapi(Request $request, Agent $agent, ChannelDriverManager $drivers): RedirectResponse|JsonResponse
    {
        $this->authorize('channels.manage', $agent);

        $data = $request->validate([
            'ig_username' => ['required', 'string', 'max:255'],
            'ig_password' => ['required', 'string', 'min:6'],
        ], [], [
            'ig_username' => 'логин Instagram',
            'ig_password' => 'пароль Instagram',
        ]);

        $channel = $agent->channels()->firstOrNew(['type' => 'instagrapi']);
        $channel->credentials = $data;
        $channel->status = 'error';
        $channel->save();

        try {
            $info = $drivers->for($channel)->connect($channel);

            $channel->settings = array_merge($channel->settings ?? [], [
                'status_instance' => $info['status_instance'] ?? null,
                'username' => $info['username'] ?? null,
            ]);
            $channel->status = 'connected';
            $channel->save();

            if ($request->wantsJson()) {
                return response()->json(['channel' => $this->serialize($channel)]);
            }

            return back()->with('status', 'Instagram (instagrapi) подключён.');
        } catch (Throwable $e) {
            $channel->save();
            $message = 'Не удалось подключить Instagram (instagrapi): '.$e->getMessage();

            if ($request->wantsJson()) {
                return response()->json([
                    'message' => $message,
                    'errors' => ['ig_password' => [$message]],
                ], 422);
            }

            return back()->withErrors(['ig_password' => $message]);
        }
    }

    public function disconnectInstagrapi(Request $request, Agent $agent, Channel $channel, ChannelDriverManager $drivers): RedirectResponse|JsonResponse
    {
        $this->authorize('delete', $channel);

        try {
            $drivers->for($channel)->disconnect($channel);
        } catch (Throwable) {

        }

        $channel->update([
            'status' => 'disconnected',
            'credentials' => null,
            'settings' => null,
        ]);

        if ($request->wantsJson()) {
            return response()->json(['channel' => $this->serialize($channel)]);
        }

        return back()->with('status', 'Instagram (instagrapi) отключён.');
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
            'id_instance' => $channel->credentials['id_instance'] ?? null,
            'state_instance' => $channel->settings['state_instance'] ?? null,
            'webhook_registered' => $channel->settings['webhook_registered'] ?? null,
            'webhook_error' => $channel->settings['webhook_error'] ?? null,
            'ig_username' => $channel->credentials['ig_username'] ?? null,
            'instagram_status' => $channel->settings['status_instance'] ?? null,
        ];
    }
}
