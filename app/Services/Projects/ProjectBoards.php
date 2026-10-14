<?php

namespace App\Services\Projects;

use App\Models\ProjectBoard;
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

        try {
            return $this->create($teamId, $creatorId, 'Задачи', null, 'team', 'blue', '_inbox');
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
    ): ProjectBoard {
        return DB::transaction(function () use ($teamId, $creatorId, $name, $description, $visibility, $accent, $slug) {
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
                ['name' => 'К выполнению', 'is_done' => false],
                ['name' => 'В работе', 'is_done' => false],
                ['name' => 'Готово', 'is_done' => true],
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
}
