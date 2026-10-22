<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use App\Http\Requests\UpdateAiProviderSettingsRequest;
use App\Models\Agent;
use App\Models\Team;
use App\Services\Account\DeletesAccount;
use App\Services\Account\SharedTeamAccountDeletionException;
use App\Services\Llm\LlmProviderFactory;
use App\Services\Permissions\PermissionCatalog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SettingsController extends Controller
{
    protected const THEMES = ['system', 'light', 'dark'];

    public function index(Request $request): View
    {
        $user = $request->user();
        $team = Team::find($user->activeTeamId());

        return view('settings.index', [
            'i18nExtraNamespaces' => ['settings'],
            'activeTeam' => $team,
            'canManageAiProvider' => $user->can(PermissionCatalog::TEAM_SETTINGS_UPDATE),
            'llmProviders' => config('agent.llm_providers'),

            'statusAsToast' => true,
            'user' => $user,

            'agents' => $user->can('agents.update') ? Agent::ownedBy($user)
                ->with(['channels' => fn ($query) => $query->select('id', 'agent_id', 'type', 'status')])
                ->orderBy('name')
                ->get(['id', 'name', 'slug', 'notify_telegram_username', 'notify_telegram_chat_id', 'notify_telegram_linked_at']) : collect(),
            'sessions' => $this->sessions($request),
            'sessionsSupported' => $this->sessionsAreListable(),
        ]);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validateWithBag('profile', [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
        ], [
            'email.unique' => __('auth.validation.email_unique'),
        ]);

        $emailChanged = $data['email'] !== $user->email;

        $user->forceFill([
            'name' => $data['name'],
            'email' => $data['email'],
            'email_verified_at' => $emailChanged ? null : $user->email_verified_at,
        ])->save();

        return back()->with('status', $emailChanged
            ? __('settings.profile.saved_email_changed')
            : __('settings.profile.saved'));
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validateWithBag('password', [
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', 'min:8', 'different:current_password'],
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => __('settings.security.current_password_wrong'),
            ])->errorBag('password');
        }

        $user->forceFill(['password' => Hash::make($data['password'])])->save();

        Auth::setUser($user);
        $request->session()->put('password_hash_web', $user->password);

        return back()->with('status', __('settings.security.password_saved'));
    }

    public function logoutOtherSessions(Request $request): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validateWithBag('sessions', [
            'password' => ['required', 'string'],
        ]);

        if (! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => __('settings.security.current_password_wrong'),
            ])->errorBag('sessions');
        }

        Auth::logoutOtherDevices($data['password']);

        if ($this->sessionsAreListable()) {
            DB::table('sessions')
                ->where('user_id', $user->id)
                ->where('id', '!=', $request->session()->getId())
                ->delete();
        }

        return back()->with('status', __('settings.security.other_sessions_ended'));
    }

    public function updatePreferences(Request $request): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validateWithBag('preferences', [
            'locale' => ['required', 'string', Rule::in(SetLocale::SUPPORTED_LOCALES)],
            'theme' => ['required', 'string', Rule::in(self::THEMES)],
            'lead_notifications_enabled' => ['nullable', 'boolean'],
        ]);

        $user->forceFill([
            'locale' => $data['locale'],

            'theme' => $data['theme'],
            'lead_notifications_enabled' => $request->boolean('lead_notifications_enabled'),
        ])->save();

        session(['locale' => $data['locale']]);
        App::setLocale($data['locale']);
        URL::defaults(['locale' => $data['locale']]);

        return back()->with('status', __('settings.preferences.saved'));
    }

    public function updateTheme(Request $request): JsonResponse
    {
        $data = $request->validate([
            'theme' => ['required', 'string', Rule::in(self::THEMES)],
        ]);

        $request->user()->forceFill([
            'theme' => $data['theme'],
        ])->save();

        return response()->json(['theme' => $data['theme']]);
    }

    public function updateAiProvider(UpdateAiProviderSettingsRequest $request): RedirectResponse
    {
        $team = Team::findOrFail($request->user()->activeTeamId());

        $team->forceFill($request->teamAttributes())->save();

        return back()->with('status', __('settings.ai_provider.saved'));
    }

    public function testAiProviderConnection(Request $request, LlmProviderFactory $factory): JsonResponse
    {
        abort_unless($request->user()->can(PermissionCatalog::TEAM_SETTINGS_UPDATE), 403);

        $data = $request->validate([
            'ai_provider' => ['required', 'string', Rule::in(config('agent.llm_providers'))],
            'ai_model' => ['nullable', 'string', 'max:100'],
            'ollama_base_url' => ['nullable', 'string', 'url', 'max:255'],
        ]);

        $result = $factory->testConnection($data['ai_provider'], $data);

        if (! $result['ok']) {
            return response()->json([
                'ok' => false,
                'message' => __('settings.ai_provider.test_failed', ['error' => $result['error']]),
            ], 502);
        }

        return response()->json(['ok' => true, 'message' => __('settings.ai_provider.test_succeeded')]);
    }

    public function ollamaModels(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(PermissionCatalog::TEAM_SETTINGS_UPDATE), 403);

        $data = $request->validate([
            'ollama_base_url' => ['nullable', 'string', 'url', 'max:255'],
        ]);

        $baseUrl = rtrim($data['ollama_base_url'] ?? (string) config('agent.ollama.base_url'), '/');

        try {
            $response = Http::timeout(5)->acceptJson()->get("{$baseUrl}/api/tags");
        } catch (ConnectionException) {
            return response()->json(['models' => [], 'reachable' => false]);
        }

        if ($response->failed()) {
            return response()->json(['models' => [], 'reachable' => false]);
        }

        $models = collect($response->json('models', []))
            ->pluck('name')
            ->filter()
            ->values();

        return response()->json(['models' => $models, 'reachable' => true]);
    }

    public function destroy(Request $request): RedirectResponse|JsonResponse
    {
        $user = $request->user();

        $request->validateWithBag('account', [
            'confirmation' => ['required', 'string'],
        ]);

        $typed = trim((string) $request->input('confirmation'));
        $accepted = mb_strtolower($typed) === mb_strtolower($user->email) || $typed === 'DELETE';

        if (! $accepted) {
            throw ValidationException::withMessages([
                'confirmation' => __('settings.account.delete_confirmation_wrong'),
            ])->errorBag('account');
        }

        try {
            app(DeletesAccount::class)->delete($user);
        } catch (SharedTeamAccountDeletionException) {
            throw ValidationException::withMessages([
                'confirmation' => __('settings.account.shared_team_blocked'),
            ])->errorBag('account');
        }

        Auth::logoutCurrentDevice();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/')->with('status', __('settings.account.deleted'));
    }

    protected function sessions(Request $request): array
    {
        if (! $this->sessionsAreListable()) {
            return [];
        }

        return DB::table('sessions')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('last_activity')
            ->limit(25)
            ->get()
            ->map(fn ($session) => [
                'id' => $session->id,
                'current' => $session->id === $request->session()->getId(),
                'ip' => $session->ip_address,
                'device' => $this->describeAgent((string) $session->user_agent),
                'last_active' => $session->last_activity,
            ])
            ->all();
    }

    protected function sessionsAreListable(): bool
    {
        return config('session.driver') === 'database';
    }

    protected function describeAgent(string $userAgent): string
    {
        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'OPR/') || str_contains($userAgent, 'Opera') => 'Opera',
            str_contains($userAgent, 'Firefox') => 'Firefox',
            str_contains($userAgent, 'Chrome') => 'Chrome',
            str_contains($userAgent, 'Safari') => 'Safari',
            default => null,
        };

        $platform = match (true) {
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'iPhone') || str_contains($userAgent, 'iPad') => 'iOS',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Mac OS') => 'macOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };

        $parts = array_filter([$browser, $platform]);

        return $parts === [] ? __('settings.security.unknown_device') : implode(' · ', $parts);
    }
}
