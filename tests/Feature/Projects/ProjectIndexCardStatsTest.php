<?php

namespace Tests\Feature\Projects;

use App\Models\Role;
use App\Models\Task;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Projects\ProjectBoards;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PHASE 3 item 17: the "Boards" heading used to render twice — once as an
 * eyebrow, once as the h2 right under it, the same string both times. Board
 * cards showed only an open-task count, with no sign a board needed
 * attention (overdue work) or who it belonged to (member count).
 */
class ProjectIndexCardStatsTest extends TestCase
{
    use RefreshDatabase;

    private function member(User $owner, string $role): User
    {
        $member = User::factory()->create();
        TeamMembership::create([
            'team_id' => $owner->current_team_id,
            'user_id' => $member->id,
            'role_id' => Role::where('team_id', $owner->current_team_id)->where('slug', $role)->sole()->id,
            'joined_at' => now(),
        ]);
        $member->forceFill(['current_team_id' => $owner->current_team_id])->save();

        return $member;
    }

    public function test_the_boards_heading_is_not_duplicated(): void
    {
        $owner = User::factory()->create();
        app(ProjectBoards::class)->create($owner->current_team_id, $owner->id, 'A board', null, 'team');

        $html = $this->actingAs($owner)->get(route('projects.index'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, __('projects.boards')));
    }

    public function test_a_team_boards_member_count_is_the_whole_team(): void
    {
        $owner = User::factory()->create();
        $this->member($owner, 'operator');
        $this->member($owner, 'viewer');
        $board = app(ProjectBoards::class)->create($owner->current_team_id, $owner->id, 'Team board', null, 'team');
        $column = $board->columns()->first();
        Task::factory()->for($owner)->create(['project_board_id' => $board->id, 'project_column_id' => $column->id, 'due_at' => now()->subDay()]);

        $html = $this->actingAs($owner)->get(route('projects.index'))->assertOk()->getContent();

        // owner + 2 members = 3.
        $this->assertMatchesRegularExpression('/'.preg_quote(__('projects.members'), '/').'\s*<strong[^>]*>3</', $html);
        $this->assertMatchesRegularExpression('/'.preg_quote(__('projects.overdue'), '/').'[^<]*<strong[^>]*>1</', $html);
    }

    public function test_a_private_boards_member_count_is_the_board_pivot_not_the_whole_team(): void
    {
        $owner = User::factory()->create();
        $operator = $this->member($owner, 'operator');
        $this->member($owner, 'viewer'); // not added to the private board below
        $board = app(ProjectBoards::class)->create($owner->current_team_id, $owner->id, 'Private board', null, 'private');
        $board->members()->attach($operator->id, ['team_id' => $owner->current_team_id]);

        $html = $this->actingAs($owner)->get(route('projects.index'))->assertOk()->getContent();

        // creator (auto-added at creation) + the one explicitly attached = 2,
        // not 3 (the whole team, which only applies to a 'team' board).
        $this->assertMatchesRegularExpression('/'.preg_quote(__('projects.members'), '/').'\s*<strong[^>]*>2</', $html);
    }

    public function test_a_board_with_no_overdue_tasks_shows_zero_not_a_hidden_badge(): void
    {
        $owner = User::factory()->create();
        $board = app(ProjectBoards::class)->create($owner->current_team_id, $owner->id, 'Board', null, 'team');

        $html = $this->actingAs($owner)->get(route('projects.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/'.preg_quote(__('projects.overdue'), '/').'[^<]*<strong[^>]*>0</', $html);
    }
}
