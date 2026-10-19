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

/**
 * The account settings area: profile, security, preferences and the account
 * itself.
 *
 * Everything here acts on the signed-in user and nobody else — there is no
 * {user} route parameter anywhere in this controller on purpose, so there is
 * no id for a request to tamper with. `$request->user()` IS the authorization.
 */
class SettingsController extends Controller
{
    /**
     * Themes the preference accepts. All three are stored verbatim, including
     * `system` — it has to be distinguishable from a NULL column (an account
     * that never chose at all), because the two now mean different things to
     * the boot script: no choice defaults to light, `system` keeps following
     * the OS. Collapsing `system` into null, as this used to do, made every
     * existing "I picked system" account indistinguishable from "never
     * touched settings" the day that default changed.
     */
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
            // Saving here is a small, local confirmation, so the flash is shown
            // as a self-dismissing toast instead of the layout's banner — which
            // would push the whole form down on every save.
            'statusAsToast' => true,
            'user' => $user,
            // Drives the Telegram lead-alert cards: one per agent, because the
            // link is made through that agent's own bot.
            //
            // `channels` is eager-loaded because partials/lead-notify-card reads
            // `$agent->channels` to decide whether the agent's Telegram channel
            // is connected — one query per card otherwise, which grew with the
            // number of agents on the page (measured: 5 agents, 5 queries).
            //
            // The column list is deliberate, not incidental tidiness: `channels`
            // also holds `credentials` (the encrypted bot token) and a `settings`
            // JSON blob, and the card needs neither. Loading the whole row to
            // answer one boolean would pull every agent's bot token into memory
            // to render a settings page. Anything added here that needs more of
            // a channel than type+status has to widen this list.
            'agents' => $user->can('agents.update') ? Agent::ownedBy($user)
                ->with(['channels' => fn ($query) => $query->select('id', 'agent_id', 'type', 'status')])
                ->orderBy('name')
                ->get(['id', 'name', 'slug', 'notify_telegram_username', 'notify_telegram_chat_id', 'notify_telegram_linked_at']) : collect(),
            'sessions' => $this->sessions($request),
            'sessionsSupported' => $this->sessionsAreListable(),
        ]);
    }

    /**
     * Name and email.
     *
     * Changing the email drops the verified stamp: the new address has not
     * proved it belongs to this person, and leaving the old stamp in place
     * would silently vouch for an address nobody confirmed.
     */
    public function updateProfile(Request $request): RedirectResponse
    {
        $user = $request->user();

        // Named bags throughout this controller: four forms share one page, and
        // a default bag would light up the password field's error under the
        // profile inputs.
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

    /**
     * Change password. The current one is required so that a borrowed, still
     * signed-in browser can't be used to lock the real owner out.
     */
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

        // Keep THIS session signed in — the password hash it carries just went
        // stale, and without this the owner would be logged out by their own
        // successful password change.
        Auth::setUser($user);
        $request->session()->put('password_hash_web', $user->password);

        return back()->with('status', __('settings.security.password_saved'));
    }

    /**
     * Sign out every other browser, keeping this one. Password-gated for the
     * same reason the change above is.
     */
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

        // logoutOtherDevices cycles the stored hash; the database session driver
        // additionally keeps rows around, so drop the other ones outright rather
        // than waiting for them to be rejected on next use.
        if ($this->sessionsAreListable()) {
            DB::table('sessions')
                ->where('user_id', $user->id)
                ->where('id', '!=', $request->session()->getId())
                ->delete();
        }

        return back()->with('status', __('settings.security.other_sessions_ended'));
    }

    /**
     * Interface language, theme and notification preferences.
     *
     * Locale is written the same way LocaleController writes it (user record +
     * session) so the two entry points can't disagree about the current
     * language, and the redirect below is re-rendered in the new one.
     */
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
            // Stored as typed — see the THEMES docblock for why `system` is
            // no longer folded into null.
            'theme' => $data['theme'],
            'lead_notifications_enabled' => $request->boolean('lead_notifications_enabled'),
        ])->save();

        session(['locale' => $data['locale']]);
        App::setLocale($data['locale']);
        URL::defaults(['locale' => $data['locale']]);

        return back()->with('status', __('settings.preferences.saved'));
    }

    /**
     * Theme only, for the toggle in the sidebar.
     *
     * That toggle used to write localStorage alone, which meant the preference
     * set here and the one set there disagreed the moment you used both. Both
     * now write the same record; localStorage stays as the pre-paint cache that
     * avoids a flash of the wrong theme before this value is known.
     */
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

    /**
     * Провайдер LLM, модель и base_url Ollama для активной команды.
     */
    public function updateAiProvider(UpdateAiProviderSettingsRequest $request): RedirectResponse
    {
        $team = Team::findOrFail($request->user()->activeTeamId());

        $team->forceFill($request->teamAttributes())->save();

        return back()->with('status', __('settings.ai_provider.saved'));
    }

    /**
     * Пробный запрос к провайдеру с тем, что сейчас введено в форме — ничего
     * не сохраняет, так что можно проверить Ollama до того, как owner нажмёт
     * "Save".
     */
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

    /**
     * Модели, установленные в Ollama (GET {base_url}/api/tags) — для
     * выпадающего списка. Недоступный сервер отдаёт пустой список, а не
     * ошибку: форма сама откатывается на свободный ввод модели.
     */
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

    /**
     * Delete the account and everything hanging off it.
     *
     * The confirmation phrase is checked server-side too: the modal is a
     * courtesy, not the control. Agents, and through them channels, knowledge,
     * conversations, messages and leads, go with the row via the foreign keys
     * declared in their migrations. `DeletesAccount` exists because two of
     * those foreign keys (deals/customers against crm_stages/crm_workspaces)
     * are RESTRICT, not CASCADE, and MySQL's own cascade order trips them —
     * see that service's docblock.
     */
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

        // The user row is gone. Full logout rotates the remember token by
        // writing that row again; clearing this device is sufficient because
        // any old remember cookie can no longer resolve a deleted account.
        Auth::logoutCurrentDevice();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/')->with('status', __('settings.account.deleted'));
    }

    /**
     * Other browsers currently signed in as this user.
     *
     * Only the database session driver keeps rows we can enumerate; on file or
     * cookie drivers the section degrades to the "sign out everywhere" button
     * rather than showing an empty list that would read as "no other devices".
     *
     * @return array<int, array<string, mixed>>
     */
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

    /**
     * A short, human "Chrome · Windows" from a user-agent string. Deliberately
     * coarse — this exists so the owner can recognise their own devices, not to
     * fingerprint them.
     */
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
