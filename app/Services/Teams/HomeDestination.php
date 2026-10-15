<?php

namespace App\Services\Teams;

use App\Models\User;
use App\Services\Permissions\PermissionCatalog;
use Illuminate\Support\Facades\Route as RouteFacade;

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
}
