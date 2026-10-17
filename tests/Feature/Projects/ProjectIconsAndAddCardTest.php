<?php

namespace Tests\Feature\Projects;

use App\Models\ProjectChecklistItem;
use App\Models\Role;
use App\Models\Task;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Projects\ProjectBoards;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ProjectIconsAndAddCardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * PRJ-009: these Unicode marks (due date, checklist count, private badge,
     * close, arrows, check, square) used to be typed directly into the Blade
     * views. They now come from the shared <x-icon> SVG component instead.
     */
    private function assertNoLiteralGlyphIcons(TestResponse $response): void
    {
        $content = $response->getContent();
        foreach (['◷', '☑', '◈', '✕', '×', '✓', '□', '←', '→'] as $glyph) {
            $this->assertStringNotContainsString($glyph, $content, "Found literal glyph icon [{$glyph}] in rendered output");
        }
    }

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

    public function test_board_task_panel_my_work_and_index_views_contain_no_literal_glyph_icons(): void
    {
        $owner = User::factory()->create();
        $board = app(ProjectBoards::class)->create($owner->current_team_id, $owner->id, 'Glyph check', null, 'private');
        $column = $board->columns()->first();
        $task = Task::factory()->for($owner)->create([
            'project_board_id' => $board->id,
            'project_column_id' => $column->id,
            'title' => 'Ship the thing',
            'due_at' => now()->addDay(),
        ]);
        ProjectChecklistItem::create(['team_id' => $owner->current_team_id, 'board_id' => $board->id, 'task_id' => $task->id, 'title' => 'Done one', 'position' => 1, 'completed_at' => now()]);
        ProjectChecklistItem::create(['team_id' => $owner->current_team_id, 'board_id' => $board->id, 'task_id' => $task->id, 'title' => 'Open one', 'position' => 2]);
        $board->labels()->create(['team_id' => $owner->current_team_id, 'name' => 'Release', 'color' => 'rose']);

        $this->actingAs($owner);

        $this->assertNoLiteralGlyphIcons($this->get(route('projects.index'))->assertOk());
        $this->assertNoLiteralGlyphIcons($this->get(route('projects.my'))->assertOk());
        $this->assertNoLiteralGlyphIcons($this->get(route('projects.boards.show', $board))->assertOk());
        $this->assertNoLiteralGlyphIcons(
            $this->get(route('projects.boards.show', [$board, 'task' => $task->id]))->assertOk()
        );

        // The "no open tasks" empty state has its own icon (a check mark), not
        // reachable from the populated my-work page fetched above.
        $task->delete();
        $this->assertNoLiteralGlyphIcons($this->get(route('projects.my'))->assertOk());
    }

    public function test_add_card_form_still_posts_to_the_tasks_store_route_with_the_same_fields(): void
    {
        $owner = User::factory()->create();
        $board = app(ProjectBoards::class)->defaultForTeam($owner->current_team_id, $owner->id);
        $column = $board->columns()->first();

        $response = $this->actingAs($owner)->get(route('projects.boards.show', $board))->assertOk();

        $response->assertSee('action="'.route('projects.tasks.store', $board).'"', false);
        $response->assertSee('name="column_id"', false);
        $response->assertSee('value="'.$column->id.'"', false);
        $response->assertSee('name="title"', false);
        $response->assertSee(__('projects.add_task'));

        $this->post(route('projects.tasks.store', $board), [
            'column_id' => $column->id,
            'title' => 'Posted from the collapsed form',
        ])->assertRedirect();
        $this->assertDatabaseHas('tasks', ['title' => 'Posted from the collapsed form', 'project_board_id' => $board->id]);
    }

    public function test_viewer_role_sees_no_add_card_control_on_the_default_board(): void
    {
        $owner = User::factory()->create();
        $viewer = $this->member($owner, 'viewer');
        $board = app(ProjectBoards::class)->defaultForTeam($owner->current_team_id, $owner->id);

        $response = $this->actingAs($viewer)->get(route('projects.boards.show', $board))->assertOk();

        $response->assertDontSee('action="'.route('projects.tasks.store', $board).'"', false);
        $response->assertDontSee(__('projects.add_task'));
    }
}
