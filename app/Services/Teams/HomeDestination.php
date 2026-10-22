<?php

namespace App\Services\Teams;

use App\Models\Agent;
use App\Models\User;
use App\Services\Permissions\PermissionCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route as RouteFacade;
use Symfony\Component\HttpKernel\Exception\HttpException;

class HomeDestination
{
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

    public function routeName(User $user): ?string
    {
        foreach (self::CANDIDATES as $candidate) {
            if (! RouteFacade::has($candidate['route']) || ! $user->can($candidate['permission'])) {
                continue;
            }

            if ($candidate['route'] === 'dashboard'
                && $user->can(PermissionCatalog::AGENTS_VIEW)
                && $this->activeTeamHasNoAgents($user)) {
                return 'agents.index';
            }

            return $candidate['route'];
        }

        return null;
    }

    private function activeTeamHasNoAgents(User $user): bool
    {
        return ! Agent::ownedBy($user)->exists();
    }

    public function stillOpen(User $user, string $routeName): bool
    {
        foreach (self::CANDIDATES as $candidate) {
            if ($candidate['route'] === $routeName) {
                return $user->can($candidate['permission']);
            }
        }

        return true;
    }

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
