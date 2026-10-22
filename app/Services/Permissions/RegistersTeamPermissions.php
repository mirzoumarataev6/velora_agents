<?php

namespace App\Services\Permissions;

use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

final class RegistersTeamPermissions
{
    public function register(): void
    {

        Gate::before(function (User $user, string $ability, array $arguments): ?bool {
            if (! in_array($ability, PermissionCatalog::all(), true)) {
                return null;
            }

            $teamId = $this->teamId($user, $arguments);

            return $teamId !== null && $user->isOwnerOf($teamId) ? true : null;
        });

        foreach (PermissionCatalog::all() as $permission) {
            Gate::define($permission, fn (User $user, ...$arguments): bool => $user->hasPermissionOn($this->teamId($user, $arguments), $permission));
        }
    }

    private function teamId(User $user, array $arguments): ?int
    {

        if ($arguments === []) {
            return $user->activeTeamId();
        }

        $subject = $arguments[0];

        return match (true) {
            $subject instanceof Team => $subject->getKey(),
            $subject instanceof Model => app(TeamResolver::class)->resolveTeamId($subject),
            is_int($subject) => $subject,
            default => null,
        };
    }
}
