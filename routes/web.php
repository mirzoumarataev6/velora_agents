<?php

use App\Http\Controllers\AgentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChannelController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DatabaseConnectionController;
use App\Http\Controllers\DatasetController;
use App\Http\Controllers\KnowledgeSourceController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\LeadNotificationController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\PersonaAssistantController;
use App\Http\Controllers\PlaygroundController;
use App\Http\Controllers\RootRedirectController;
use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::get('/', RootRedirectController::class);

Route::post('/locale', [LocaleController::class, 'update'])->name('locale.update');

// Stable, unprefixed — form targets and the Google OAuth callback must not
// move with the locale, since a form's action doesn't need a locale segment
// and the OAuth redirect URI may already be registered with Google.
Route::middleware('guest')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/forgot-password', [PasswordResetController::class, 'email'])->name('password.email');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])->name('password.update');
    Route::get('/auth/google', [AuthController::class, 'redirectToGoogle'])->name('auth.google');
    Route::get('/auth/google/callback', [AuthController::class, 'handleGoogleCallback']);
});

// `auth.session` is what gives "sign out other devices" teeth: it compares the
// password hash each session carries against the current one, so cycling the
// hash actually ejects the other browsers instead of merely intending to.
Route::middleware(['auth', 'auth.session'])->group(function () {
    // The panel's front page. `/home` is where every post-login redirect lands,
    // so pointing it here is what makes the dashboard the landing surface
    // without touching the handful of places that name that route.
    Route::redirect('/home', '/dashboard')->name('home');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    // The only onboarding endpoint. Step progress is derived from the account's
    // own data at render time, so there is nothing to report back — only the
    // decision to skip needs recording.
    Route::delete('onboarding', [OnboardingController::class, 'dismiss'])->name('onboarding.dismiss');

    // Leads / Заявки. The static segments (poll, seen) are declared before the
    // {lead} wildcard so they aren't swallowed as a model-bound id.
    Route::get('leads', [LeadController::class, 'index'])->name('leads.index');
    Route::get('leads/poll', [LeadController::class, 'poll'])->name('leads.poll');
    Route::post('leads/seen', [LeadController::class, 'markSeen'])->name('leads.seen');
    Route::patch('leads/{lead}', [LeadController::class, 'update'])->name('leads.update');
    // Price-haggling decisions. Their own endpoints rather than another status
    // value on `update`: approving is not a workflow move, it commits the
    // business to a number and sends a message to the customer.
    Route::post('leads/{lead}/price/approve', [LeadController::class, 'approvePrice'])->name('leads.price.approve');
    Route::post('leads/{lead}/price/decline', [LeadController::class, 'declinePrice'])->name('leads.price.decline');
    Route::delete('leads/{lead}', [LeadController::class, 'destroy'])->name('leads.destroy');

    // Unified inbox across every agent and channel. `list` is declared before
    // the {conversation} wildcard so it isn't bound as a model id.
    Route::get('conversations', [ConversationController::class, 'index'])->name('conversations.index');
    Route::get('conversations/list', [ConversationController::class, 'list'])->name('conversations.list');
    Route::get('conversations/{conversation}', [ConversationController::class, 'show'])->name('conversations.show');
    Route::post('conversations/{conversation}/reply', [ConversationController::class, 'reply'])->name('conversations.reply');
    Route::patch('conversations/{conversation}/status', [ConversationController::class, 'updateStatus'])->name('conversations.status');

    // Account settings. No {user} parameter anywhere — every action here acts
    // on the signed-in user, so there is no id for a request to tamper with.
    Route::prefix('settings')->name('settings.')->group(function () {
        Route::get('/', [SettingsController::class, 'index'])->name('index');
        Route::patch('profile', [SettingsController::class, 'updateProfile'])->name('profile');
        Route::put('password', [SettingsController::class, 'updatePassword'])->name('password');
        Route::delete('sessions', [SettingsController::class, 'logoutOtherSessions'])->name('sessions');
        Route::patch('preferences', [SettingsController::class, 'updatePreferences'])->name('preferences');
        Route::put('theme', [SettingsController::class, 'updateTheme'])->name('theme');
        Route::delete('account', [SettingsController::class, 'destroy'])->name('account.destroy');
    });

    // Declared before the resource so it reads as a sibling of the form it
    // serves. Throttled because each call is a real LLM request the owner pays
    // for, and the button is one click away from being held down.
    Route::post('agents/persona-assistant', PersonaAssistantController::class)
        ->middleware('throttle:10,1')->name('agents.persona-assistant');

    Route::resource('agents', AgentController::class)->parameters(['agents' => 'agent']);
    Route::patch('agents/{agent}/status', [AgentController::class, 'updateStatus'])->name('agents.status');

    // Owner lead-notification linking (per agent, reuses the agent's Telegram bot).
    Route::prefix('agents/{agent}/notify/telegram')->name('agents.notify.telegram.')->group(function () {
        Route::post('/', [LeadNotificationController::class, 'link'])->name('link');
        Route::get('/', [LeadNotificationController::class, 'status'])->name('status');
        Route::delete('/', [LeadNotificationController::class, 'unlink'])->name('unlink');
    });

    Route::prefix('agents/{agent}/knowledge')->name('agents.knowledge.')->scopeBindings()->group(function () {
        Route::get('status', [KnowledgeSourceController::class, 'status'])->name('status');
        Route::post('files', [KnowledgeSourceController::class, 'storeFile'])->name('files.store');
        Route::post('text', [KnowledgeSourceController::class, 'storeText'])->name('text.store');
        Route::post('url', [KnowledgeSourceController::class, 'storeUrl'])->name('url.store');
        Route::post('{knowledgeSource}/refetch', [KnowledgeSourceController::class, 'refetch'])->name('refetch');
        Route::delete('{knowledgeSource}', [KnowledgeSourceController::class, 'destroy'])->name('destroy');
    });

    // Structured product catalog (Каталог). Separate from the knowledge base
    // above: that one chunks text for semantic retrieval, this one imports
    // row-per-product so the agent can filter by price and stock exactly.
    Route::prefix('agents/{agent}/catalog')->name('agents.catalog.')->group(function () {
        Route::get('/', [DatasetController::class, 'show'])->name('show');
        // The owner's paginated browser for their own catalog — unrelated to
        // what the agent shows customers, which search_products caps separately.
        Route::get('products', [DatasetController::class, 'products'])->name('products');
        Route::post('analyze', [DatasetController::class, 'analyze'])->name('analyze');
        Route::post('import', [DatasetController::class, 'import'])->name('import');
        Route::post('cancel', [DatasetController::class, 'cancel'])->name('cancel');
        Route::delete('/', [DatasetController::class, 'destroy'])->name('destroy');
    });

    // Live database connection (Подключение к базе данных) — the ADVANCED
    // alternative to the uploaded catalog above. An agent uses one or the other,
    // never both; `source` is what switches between them.
    Route::prefix('agents/{agent}/database')->name('agents.database.')->group(function () {
        Route::get('/', [DatabaseConnectionController::class, 'show'])->name('show');
        // Dials a host and port the caller chose, so it is throttled whether or
        // not private ranges are allowed — otherwise this is a port scanner.
        Route::post('test', [DatabaseConnectionController::class, 'test'])
            ->middleware('throttle:database-test')->name('test');
        Route::put('/', [DatabaseConnectionController::class, 'store'])
            ->middleware('throttle:database-test')->name('store');
        // Automatic discovery. Throttled with the connection endpoints: it opens
        // a connection, reads a whole catalog, and — for a schema the
        // deterministic pass cannot settle, when that is switched on — may make
        // one model call.
        Route::post('discover', [DatabaseConnectionController::class, 'discover'])
            ->middleware('throttle:database-test')->name('discover');
        Route::put('discovery', [DatabaseConnectionController::class, 'confirmDiscovery'])
            ->middleware('throttle:database-test')->name('discovery.confirm');
        Route::get('tables', [DatabaseConnectionController::class, 'tables'])->name('tables');
        Route::post('describe', [DatabaseConnectionController::class, 'describe'])->name('describe');
        Route::put('mapping', [DatabaseConnectionController::class, 'mapping'])->name('mapping');
        Route::put('source', [DatabaseConnectionController::class, 'source'])->name('source');
        Route::delete('/', [DatabaseConnectionController::class, 'destroy'])->name('destroy');
    });

    Route::prefix('agents/{agent}/playground')->name('agents.playground.')->group(function () {
        Route::post('messages', [PlaygroundController::class, 'store'])->name('messages.store');
        Route::post('transcribe', [PlaygroundController::class, 'transcribe'])->name('transcribe');
        Route::delete('/', [PlaygroundController::class, 'reset'])->name('reset');
    });

    Route::prefix('agents/{agent}/channels')->name('agents.channels.')->scopeBindings()->group(function () {
        Route::post('telegram', [ChannelController::class, 'connectTelegram'])->name('telegram.connect');
        Route::delete('telegram/{channel}', [ChannelController::class, 'disconnectTelegram'])->name('telegram.disconnect');

        Route::post('whatsapp', [ChannelController::class, 'connectWhatsApp'])->name('whatsapp.connect');
        Route::delete('whatsapp/{channel}', [ChannelController::class, 'disconnectWhatsApp'])->name('whatsapp.disconnect');

        Route::post('instagram', [ChannelController::class, 'connectInstagram'])->name('instagram.connect');
        Route::delete('instagram/{channel}', [ChannelController::class, 'disconnectInstagram'])->name('instagram.disconnect');
    });
});

// The locale-prefixed group is registered LAST so its single-segment {locale}
// wildcard route doesn't shadow static single-segment routes above (e.g. a
// request for /agents or /home must resolve to the real route, not be
// swallowed as a "locale" value by the welcome route's {locale} pattern).
Route::prefix('{locale}')->group(function () {
    // Route::view (not a closure) so `route:cache` works. These views pull
    // everything they need from __() and old(); no controller data is required.
    //
    // The marketing page carries no per-user data and no CSRF token, so it's
    // safe to let browsers cache it briefly — a returning visitor gets an
    // instant paint. Dark mode and the locale switch are client-side, so a
    // cached copy still respects them. Auth pages below are deliberately NOT
    // cached (they carry CSRF tokens and guest-only state).
    // `showcase` is the current landing; `welcome` is the retired one, kept in
    // the repo but no longer routed. The route NAME stays `welcome` because
    // route('welcome') is the brand link on the auth layout.
    Route::view('/', 'showcase')->name('welcome')
        ->middleware('cache.headers:public;max_age=300;etag');

    Route::middleware('guest')->group(function () {
        Route::view('/login', 'auth.login')->name('login');
        Route::view('/register', 'auth.register')->name('register');
        Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
        Route::get('/reset-password/{token}', [PasswordResetController::class, 'reset'])->name('password.reset');
    });
});
