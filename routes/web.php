<?php

use App\Http\Controllers\AgentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Bi\AnalystChatController;
use App\Http\Controllers\Bi\ConnectionController as BiConnectionController;
use App\Http\Controllers\Bi\DashboardController as BiDashboardController;
use App\Http\Controllers\Bi\DatasetController as BiDatasetController;
use App\Http\Controllers\Bi\SourceController as BiSourceController;
use App\Http\Controllers\Bi\WidgetController as BiWidgetController;
use App\Http\Controllers\ChannelController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\Crm\CrmNoteController;
use App\Http\Controllers\Crm\CrmTrendController;
use App\Http\Controllers\Crm\CrmWorkspaceController;
use App\Http\Controllers\Crm\CustomerController;
use App\Http\Controllers\Crm\DealController;
use App\Http\Controllers\Crm\TaskController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DatabaseConnectionController;
use App\Http\Controllers\DatasetController;
use App\Http\Controllers\KnowledgeMediaController;
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
Route::get('/media/knowledge/{source}', [KnowledgeMediaController::class, 'show'])
    ->middleware('signed')->name('knowledge.media.show');

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

    // CRM. Its own top-level section, distinct from the dashboard: the
    // dashboard reports on the business, this is where the owner works the
    // business. Customers are scoped to the OWNER, not to an agent, so one
    // person writing to two agents is one profile.
    Route::prefix('crm')->name('crm.')->group(function () {
        // The board is the CRM's front page: it is where the work is.
        Route::get('/', [DealController::class, 'index'])->name('board');
        // Its own top-level page, not a tab of the board itself — a time
        // series reads differently from a row list, and the board's own
        // Alpine component is already large enough without folding a chart
        // into it. Reachable from the subnav, same as Customers/Tasks.
        Route::get('trend', [CrmTrendController::class, 'index'])->name('trend');
        // Declared before the {deal} wildcard so it isn't bound as a model id.
        Route::get('deals/poll', [DealController::class, 'poll'])->name('deals.poll');
        // CSV of exactly what the board is currently filtered to — see
        // DealController::export() for why it reuses DealBoard's own filter
        // query rather than the grouped board list.
        Route::get('deals/export', [DealController::class, 'export'])->name('deals.export');
        Route::get('deals/{deal}/activity', [DealController::class, 'activity'])->name('deals.activity');
        Route::post('deals', [DealController::class, 'store'])->name('deals.store');
        // A drag is its own endpoint, not another field on update: moving a
        // card reorders a whole column and stamps the manual override that
        // holds the automation off.
        Route::patch('deals/{deal}/move', [DealController::class, 'move'])->name('deals.move');
        Route::patch('deals/{deal}', [DealController::class, 'update'])->name('deals.update');
        Route::delete('deals/{deal}', [DealController::class, 'destroy'])->name('deals.destroy');
        Route::post('deals/{deal}/notes', [CrmNoteController::class, 'storeForDeal'])->name('deals.notes.store');

        Route::post('workspaces', [CrmWorkspaceController::class, 'store'])->name('workspaces.store');
        Route::patch('workspaces/{workspace}', [CrmWorkspaceController::class, 'update'])->name('workspaces.update');
        Route::delete('workspaces/{workspace}', [CrmWorkspaceController::class, 'destroy'])->name('workspaces.destroy');

        // Reminders, attached to a customer, a deal, both or neither.
        Route::get('tasks', [TaskController::class, 'index'])->name('tasks.index');
        Route::post('tasks', [TaskController::class, 'store'])->name('tasks.store');
        Route::patch('tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');
        Route::delete('tasks/{task}', [TaskController::class, 'destroy'])->name('tasks.destroy');

        // The customer directory is a first-class CRM view; search, lookup
        // and export stay before the wildcard so they cannot be mistaken for
        // profile IDs.
        Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
        Route::get('customers/search', [CustomerController::class, 'search'])->name('customers.search');
        Route::get('customers/lookup', [CustomerController::class, 'lookup'])->name('customers.lookup');
        Route::get('customers/export', [CustomerController::class, 'export'])->name('customers.export');
        // The manual "+ Add customer" — distinct from the automatic profiles
        // CustomerResolver opens from an inbound conversation or lead.
        Route::post('customers', [CustomerController::class, 'store'])->name('customers.store');
        Route::get('customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
        Route::patch('customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');
        Route::post('customers/{customer}/notes', [CrmNoteController::class, 'storeForCustomer'])->name('customers.notes.store');
        // The manual override for the identities the data cannot match on its
        // own — see CustomerResolver for why that case exists at all.
        Route::post('customers/{customer}/merge', [CustomerController::class, 'merge'])->name('customers.merge');
        Route::post('customers/{customer}/merge/{merge}/undo', [CustomerController::class, 'undoMerge'])->name('customers.merge.undo');

        Route::delete('notes/{note}', [CrmNoteController::class, 'destroy'])->name('notes.destroy');
    });

    // Data to Dashboard (Данные) — its own top-level section, a peer of CRM.
    // The dashboard at the top reports on the AGENTS; this reports on whatever
    // the owner uploads, which is a different question about different data.
    //
    // Everything here is scoped to the OWNER, like the CRM: a spreadsheet of last
    // quarter's sales belongs to the business, not to whichever assistant exists.
    Route::prefix('bi')->name('bi.')->group(function () {
        // The section opens on the boards, not on the uploads: once an owner
        // has built a dashboard it is the thing they come back for, and the
        // sources page is where you go to add data rather than to read it. The
        // empty state on the dashboards index sends a first-time visitor to the
        // upload, so nobody lands somewhere they cannot act.
        Route::redirect('/', '/bi/dashboards');

        // `/bi/data`, not `/bi/sources`. "Источник" is the agent knowledge
        // base's own noun — a knowledge SOURCE is what an assistant reads — and
        // reusing it here had owners reading this screen as "what the AI
        // knows" rather than "my business data". The route name keeps the
        // `sources.` prefix because the model is still `BiSource`; only the
        // user-facing vocabulary moved.
        //
        // `status` is declared before the {source} wildcard so it is not bound
        // as a model id.
        Route::get('data', [BiSourceController::class, 'index'])->name('sources.index');
        Route::get('data/status', [BiSourceController::class, 'status'])->name('sources.status');
        // Throttled: each call stages a file up to 100MB on our disk and queues
        // minutes of worker time. Per user, since the route is behind auth.
        Route::post('data', [BiSourceController::class, 'store'])
            ->middleware('throttle:bi-upload')->name('sources.store');
        Route::post('data/{source}/retry', [BiSourceController::class, 'retry'])
            ->middleware('throttle:bi-upload')->name('sources.retry');
        Route::post('data/{source}/analyze', [BiDashboardController::class, 'analyze'])->name('sources.analyze');
        Route::delete('data/{source}', [BiSourceController::class, 'destroy'])->name('sources.destroy');

        /*
         * THE BI MODULE'S OWN DATABASE CONNECTIONS.
         *
         * Not the agent's. These rows live in `bi_connections`, are owned by
         * `user_id`, and reference nothing in `database_connections` — see
         * BiConnection for why borrowing the agent's row was wrong.
         *
         * Everything that dials out carries the `database-test` limiter, the
         * same one the agent-side endpoints use: the limit is about opening a
         * connection to somebody's server, and that is worth rationing whoever
         * is asking.
         */
        Route::prefix('connections')->name('connections.')->group(function () {
            Route::get('/', [BiConnectionController::class, 'index'])->name('index');
            Route::post('/', [BiConnectionController::class, 'store'])
                ->middleware('throttle:database-test')->name('store');
            // Static segments before the {connection} wildcard, per the
            // section's convention. `test` with no connection is the
            // create-form's "check before saving".
            Route::post('test', [BiConnectionController::class, 'test'])
                ->middleware('throttle:database-test')->name('test');
            // Registering tables as a source. `sources` rather than nesting it
            // under a connection id: the id is in the body alongside the table
            // list, and splitting one payload across two places would make the
            // validation error land on a field the form does not have.
            Route::post('sources', [BiConnectionController::class, 'storeSource'])
                ->middleware('throttle:database-test')->name('sources');

            Route::patch('{connection}', [BiConnectionController::class, 'update'])
                ->middleware('throttle:database-test')->name('update');
            Route::delete('{connection}', [BiConnectionController::class, 'destroy'])->name('destroy');
            Route::post('{connection}/test', [BiConnectionController::class, 'test'])
                ->middleware('throttle:database-test')->name('retest');
            Route::get('{connection}/tables', [BiConnectionController::class, 'tables'])
                ->middleware('throttle:database-test')->name('tables');
        });

        // The schema drawer. `{dataset}` is model-bound and every action authorizes
        // it, so the physical table a request reaches is always one the policy has
        // already approved.
        Route::prefix('datasets/{dataset}')->name('datasets.')->group(function () {
            Route::get('/', [BiDatasetController::class, 'show'])->name('show');
            Route::patch('/', [BiDatasetController::class, 'update'])->name('update');
            Route::patch('columns', [BiDatasetController::class, 'updateColumns'])->name('columns');
            // Its own endpoint rather than another field on `columns`: rebuilding a
            // table is not an edit, it is minutes of worker time the owner is asking
            // for deliberately.
            Route::post('rematerialize', [BiDatasetController::class, 'rematerialize'])
                ->middleware('throttle:bi-upload')->name('rematerialize');
            Route::delete('/', [BiDatasetController::class, 'destroy'])->name('destroy');
        });

        // Boards. `layout` and `widgets` are declared inside the {dashboard}
        // group, and every static segment inside them comes before any
        // wildcard, per the section's convention.
        Route::prefix('dashboards')->name('dashboards.')->group(function () {
            Route::get('/', [BiDashboardController::class, 'index'])->name('index');
            Route::post('/', [BiDashboardController::class, 'store'])->name('store');

            Route::get('{dashboard}', [BiDashboardController::class, 'show'])->name('show');
            Route::patch('{dashboard}', [BiDashboardController::class, 'update'])->name('update');
            Route::delete('{dashboard}', [BiDashboardController::class, 'destroy'])->name('destroy');

            // A drag writes every card on the board at once. Throttled because
            // gridstack fires on every drop and a held-down resize can produce
            // a burst — the limiter is what keeps that from becoming a write
            // per animation frame.
            Route::put('{dashboard}/layout', [BiDashboardController::class, 'updateLayout'])
                ->middleware('throttle:bi-layout')->name('layout');
        });

        // Cards, nested under their board and scope-bound: a widget id from
        // another board 404s at the router rather than at a policy.
        Route::prefix('dashboards/{dashboard}/widgets')->name('widgets.')->scopeBindings()->group(function () {
            Route::post('/', [BiWidgetController::class, 'store'])->name('store');
            Route::get('{widget}/data', [BiWidgetController::class, 'data'])->name('data');
            Route::patch('{widget}', [BiWidgetController::class, 'update'])->name('update');
            Route::delete('{widget}', [BiWidgetController::class, 'destroy'])->name('destroy');
        });
        Route::post('dashboards/{dashboard}/chat', [AnalystChatController::class, 'store'])
            ->middleware('throttle:10,1')->name('chat.store');
        Route::post('dashboards/{dashboard}/chat/{message}/undo', [AnalystChatController::class, 'undo'])
            ->name('chat.undo');
    });

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

        Route::post('greenapi', [ChannelController::class, 'connectGreenApi'])->name('greenapi.connect');
        Route::delete('greenapi/{channel}', [ChannelController::class, 'disconnectGreenApi'])->name('greenapi.disconnect');

        Route::post('instagrapi', [ChannelController::class, 'connectInstagrapi'])->name('instagrapi.connect');
        Route::delete('instagrapi/{channel}', [ChannelController::class, 'disconnectInstagrapi'])->name('instagrapi.disconnect');
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
