<?php

namespace App\Services\Teams;

use App\Models\User;
use App\Services\Permissions\PermissionCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route as RouteFacade;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Where a signed-in user should land when nothing more specific is already
 * decided for them — after login/register, after accepting an invitation,
 * and (when the page they were on stops being open to them) after switching
 * teams.
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
     * The route NAME rather than a URL, so callers that need to compare
     * against an already-resolved route (TeamSwitchController, deciding
     * whether the page it is about to send the user `back()` to survives a
     * team switch) can do it without re-parsing a URL back into a name.
     */
    public function routeName(User $user): ?string
    {
        foreach (self::CANDIDATES as $candidate) {
            if (RouteFacade::has($candidate['route']) && $user->can($candidate['permission'])) {
                return $candidate['route'];
            }
        }

        return null;
    }

    /**
     * Whether the user could still land on the page behind this route name
     * right now — used by TeamSwitchController to decide whether `back()`
     * is safe after the active team just changed. Deliberately narrow: it
     * only answers for the routes THIS class already knows how to gate
     * (the ones named above), not an arbitrary URL. A route it does not
     * recognize is assumed fine to return to — this is a safety net for
     * the known gated sections the nav itself hides, not a general-purpose
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
