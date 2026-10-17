<?php

namespace App\Services\Projects;

use App\Http\Middleware\SetLocale;
use App\Models\ProjectBoard;
use App\Models\TeamMembership;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProjectBoards
{
    public function defaultForTeam(int $teamId, ?int $creatorId = null): ProjectBoard
    {
        $existing = ProjectBoard::where('team_id', $teamId)->where('slug', '_inbox')->first();
        if ($existing !== null) {
            return $existing;
        }

        $locale = $this->resolveLocale($teamId, $creatorId);

        try {
            // Translated at creation time, in the creator's (or else the team
            // owner's) saved locale. The name is then plain text on the row: it is
            // NOT re-translated if that locale later changes, because it is
            // editable and a silent rewrite would clobber a name someone already
            // chose for themselves.
            return $this->create($teamId, $creatorId, __('projects.defaults.board_name', [], $locale), null, 'team', 'blue', '_inbox', $locale);
        } catch (UniqueConstraintViolationException) {
            // Two requests can open a new team's first task simultaneously.
            // The unique team+slug key decides which one created the board;
            // the loser uses that same board instead of producing twins.
            return ProjectBoard::where('team_id', $teamId)->where('slug', '_inbox')->firstOrFail();
        }
    }

    public function create(
        int $teamId,
        ?int $creatorId,
        string $name,
        ?string $description = null,
        string $visibility = 'team',
        string $accent = 'blue',
        ?string $slug = null,
        ?string $locale = null,
    ): ProjectBoard {
        $locale ??= $this->resolveLocale($teamId, $creatorId);

        return DB::transaction(function () use ($teamId, $creatorId, $name, $description, $visibility, $accent, $slug, $locale) {
            $board = ProjectBoard::create([
                'team_id' => $teamId,
                'created_by' => $creatorId,
                'name' => $name,
                'description' => $description,
                'visibility' => $visibility,
                'accent' => $accent,
                'slug' => $slug ?? 'board-'.Str::lower(Str::random(16)),
            ]);

            // Same reasoning as the default board name above: translated once, here,
            // in the resolved owner/creator locale, then stored as plain editable text.
            foreach ([
                ['name' => __('projects.defaults.column_todo', [], $locale), 'is_done' => false],
                ['name' => __('projects.defaults.column_doing', [], $locale), 'is_done' => false],
                ['name' => __('projects.defaults.column_done', [], $locale), 'is_done' => true],
            ] as $position => $column) {
                $board->columns()->create([
                    'team_id' => $teamId,
                    'position' => $position,
                    ...$column,
                ]);
            }

            if ($visibility === 'private' && $creatorId !== null) {
                DB::table('project_board_members')->insert([
                    'team_id' => $teamId,
                    'board_id' => $board->id,
                    'user_id' => $creatorId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $board;
        });
    }

    /**
     * Picks the locale for this board's default names. Deliberately NOT
     * app()->getLocale(): defaultForTeam() is also called from Task::booted()
     * on any task write — including queue jobs, the agent reply pipeline,
     * artisan commands and DemoSeeder — where the process never ran SetLocale
     * and the locale is just config('app.locale'). A Tajik or Russian team's
     * first board would silently get English names. The creator's own saved
     * preference wins when known; otherwise fall back to the team owner's,
     * since a background job still has no request-scoped locale to trust.
     */
    private function resolveLocale(int $teamId, ?int $creatorId): string
    {
        if ($creatorId !== null) {
            $creatorLocale = User::whereKey($creatorId)->value('locale');
            if (in_array($creatorLocale, SetLocale::SUPPORTED_LOCALES, true)) {
                return $creatorLocale;
            }
        }

        $ownerLocale = TeamMembership::where('team_id', $teamId)
            ->whereHas('role', fn ($query) => $query->where('is_owner', true))
            ->with('user:id,locale')
            ->first()?->user?->locale;

        if (in_array($ownerLocale, SetLocale::SUPPORTED_LOCALES, true)) {
            return $ownerLocale;
        }

        return app()->getLocale();
    }
}
