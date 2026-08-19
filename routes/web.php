<?php

use App\Http\Controllers\AgentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChannelController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\KnowledgeSourceController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\LeadNotificationController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\PasswordResetController;
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
    Route::redirect('/home', '/agents')->name('home');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    // Leads / Заявки. The static segments (poll, seen) are declared before the
    // {lead} wildcard so they aren't swallowed as a model-bound id.
    Route::get('leads', [LeadController::class, 'index'])->name('leads.index');
    Route::get('leads/poll', [LeadController::class, 'poll'])->name('leads.poll');
    Route::post('leads/seen', [LeadController::class, 'markSeen'])->name('leads.seen');
    Route::patch('leads/{lead}', [LeadController::class, 'update'])->name('leads.update');
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
