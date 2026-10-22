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

            return $this->create($teamId, $creatorId, __('projects.defaults.board_name', [], $locale), null, 'team', 'blue', '_inbox', $locale);
        } catch (UniqueConstraintViolationException) {

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
