<?php

namespace App\Services\Teams;

use App\Models\Agent;
use App\Models\User;
use App\Services\Permissions\PermissionCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route as RouteFacade;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Where a signed-in user should land when nothing more specific is already
 * decided for them — after login/register, after accepting an invitation,
 * and (unconditionally, since D-02/U-02) after switching teams.
 *
 * `route('home')` used to mean "redirect to /dashboard" unconditionally, but
 * /dashboard requires analytics.view — a role with crm.view and nothing
 * else landed on a 403 the instant it signed in. This walks the same
 * sections the nav itself gates (see layouts/dashboard.blade.php) and picks
 * the first one the role can actually open, so "where do I land" and
 * "what can I see in the nav" never disagree. /dashboard's own
 * authorization is untouched — this only decides whether to send someone
 * there, never whether they are let in once they arrive.
 */
class HomeDestination
{
    /**
     * In nav order. `team.settings.view` for the last one rather than no
     * permission at all: SettingsController::index() itself has no gate
     * (every signed-in user can always reach their own account there), but
     * a role holding literally none of these — including no view into team
     * settings — is exactly the "nothing to land on" case this class
     * exists to catch, so the chain must bottom out in `noAccess()` rather
     * than always landing on settings regardless of role.
     *
     * @var list<array{route: string, permission: string}>
     */
    private const CANDIDATES = [
        ['route' => 'dashboard', 'permission' => PermissionCatalog::ANALYTICS_VIEW],
        ['route' => 'crm.board', 'permission' => PermissionCatalog::CRM_VIEW],
        ['route' => 'conversations.index', 'permission' => PermissionCatalog::CONVERSATIONS_VIEW],
        ['route' => 'agents.index', 'permission' => PermissionCatalog::AGENTS_VIEW],
        ['route' => 'projects.index', 'permission' => PermissionCatalog::PROJECTS_VIEW],
        ['route' => 'settings.index', 'permission' => PermissionCatalog::TEAM_SETTINGS_VIEW],
    ];

    public function url(User $user): string
    {
        $name = $this->routeName($user);

        return $name === null ? route('home.no-access') : route($name);
    }

    /**
     * The route NAME rather than a URL, split out from `url()` so a caller
     * that needs the name itself (`stillOpen()`'s own kind of comparison)
     * never has to re-parse a URL back into one. `TeamSwitchController` no
     * longer calls this — after D-02/U-02 it always sends a switch to
     * `url()` outright rather than comparing against the page the user was
     * on — but `redirectIntended()` below still needs `stillOpen()`, which
     * this feeds.
     */
    public function routeName(User $user): ?string
    {
        foreach (self::CANDIDATES as $candidate) {
            if (! RouteFacade::has($candidate['route']) || ! $user->can($candidate['permission'])) {
                continue;
            }

            // /dashboard has nothing on it until there is at least one
            // conversation, and a brand-new team has neither — a new owner's
            // first screen would otherwise be empty. Only ever overrides the
            // dashboard candidate specifically, and only when agents.index
            // is itself reachable for this role; every other candidate
            // (crm.view, conversations.view, ...) is untouched.
            if ($candidate['route'] === 'dashboard'
                && $user->can(PermissionCatalog::AGENTS_VIEW)
                && $this->activeTeamHasNoAgents($user)) {
                return 'agents.index';
            }

            return $candidate['route'];
        }

        return null;
    }

    /**
     * One `exists()` against the active team only — never a count, and
     * never the stale `current_team_id` on its own (see `activeTeamId()`'s
     * docblock: a membership that no longer holds must read as "no team",
     * not as whatever team the pointer still names).
     */
    private function activeTeamHasNoAgents(User $user): bool
    {
        return ! Agent::ownedBy($user)->exists();
    }

    /**
     * Whether the user could still land on the page behind this route name
     * right now — used by `redirectIntended()` below to decide whether a
     * session-stored `url.intended` is still safe to honor after sign-in
     * (D-04). `TeamSwitchController` used to call this too, to decide
     * whether `back()` was safe after a team switch; it no longer does —
     * D-02/U-02 replaced that with an unconditional redirect to `url()`,
     * since a page bound to a specific row almost never survives a switch
     * and there is no way to enumerate every such route here. Deliberately
     * narrow: it only answers for the routes THIS class already knows how
     * to gate (the ones named above), not an arbitrary URL. A route it
     * does not recognize is assumed fine — this is a safety net for the
     * known gated sections the nav itself hides, not a general-purpose
     * authorization re-check of every possible page.
     */
    public function stillOpen(User $user, string $routeName): bool
    {
        foreach (self::CANDIDATES as $candidate) {
            if ($candidate['route'] === $routeName) {
                return $user->can($candidate['permission']);
            }
        }

        return true;
    }

    /**
     * What `AuthController::login()`/`register()`/`handleGoogleCallback()`
     * all call instead of `redirect()->intended($this->url($user))`
     * directly (D-04). `Redirector::intended()` prefers a session-stored
     * `url.intended` over the default it is passed, and that key is set by
     * Laravel's own `redirect()->guest()` for ANY unauthenticated GET to a
     * protected route — most commonly `/dashboard`, stored before the user
     * ever signed in. Honoring it unconditionally meant a role without
     * `analytics.view` could sign in only to have `redirect()->intended()`
     * send it straight back to the one page it just proved it cannot open,
     * 403ing instead of landing on this class's own safe fallback.
     *
     * Only forgets the stored URL when it resolves to one of THIS class's
     * known candidate routes and `stillOpen()` says no for this user —
     * exactly the routes `url()`/`routeName()` themselves choose between.
     * Any other stored URL (an invitation-accept link being the one that
     * matters — see `TeamInvitationController::accept()`'s own comment) is
     * left alone and still wins over the default, same as before this
     * method existed.
     */
    public function redirectIntended(Request $request, User $user): RedirectResponse
    {
        $intended = $request->session()->get('url.intended');

        if (is_string($intended)) {
            $routeName = $this->routeNameForUrl($intended);

            if ($routeName !== null && ! $this->stillOpen($user, $routeName)) {
                $request->session()->forget('url.intended');
            }
        }

        return redirect()->intended($this->url($user));
    }

    /**
     * Best-effort: an intended URL that does not resolve to a named route
     * at all (anything outside this app, or no match) is not one of
     * `stillOpen()`'s known candidates either way, so it is treated the
     * same as "not a candidate" — left alone, never forgotten.
     */
    private function routeNameForUrl(string $url): ?string
    {
        try {
            $route = RouteFacade::getRoutes()->match(Request::create($url));
        } catch (HttpException) {
            return null;
        }

        return $route->getName();
    }
}
