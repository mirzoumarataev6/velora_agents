<?php

namespace Tests\Feature\Projects;

use App\Models\Task;
use App\Models\User;
use App\Services\Projects\ProjectBoards;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PHASE 3 item 21: a task title, column, label or board name built from
 * zero-width/invisible Unicode characters is stripped before it's saved —
 * see InvisibleCharacters' own docblock for why stripped rather than
 * rejected. A name made ENTIRELY of them strips down to "" and then fails
 * that field's existing `required` rule, same as leaving it empty outright.
 */
class ProjectInvisibleCharactersTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_task_title_with_invisible_characters_is_stripped_on_create(): void
    {
        $owner = User::factory()->create();
        $board = app(ProjectBoards::class)->create($owner->current_team_id, $owner->id, 'Board', null, 'team');
        $column = $board->columns()->first();

        $this->actingAs($owner)
            ->post(route('projects.tasks.store', $board), ['column_id' => $column->id, 'title' => "Lau\u{200B}nch"])
            ->assertRedirect();

        $this->assertDatabaseHas('tasks', ['title' => 'Launch']);
        $this->assertDatabaseMissing('tasks', ['title' => "Lau\u{200B}nch"]);
    }

    public function test_a_task_title_with_invisible_characters_is_stripped_on_update(): void
    {
        $owner = User::factory()->create();
        $board = app(ProjectBoards::class)->create($owner->current_team_id, $owner->id, 'Board', null, 'team');
        $column = $board->columns()->first();
        $task = Task::factory()->for($owner)->create(['project_board_id' => $board->id, 'project_column_id' => $column->id]);

        $this->actingAs($owner)
            ->patch(route('projects.tasks.update', [$board, $task]), ['title' => "Re\u{FEFF}named"])
            ->assertRedirect();

        $this->assertSame('Renamed', $task->fresh()->title);
    }

    public function test_a_task_title_made_entirely_of_invisible_characters_fails_required_not_a_blank_save(): void
    {
        $owner = User::factory()->create();
        $board = app(ProjectBoards::class)->create($owner->current_team_id, $owner->id, 'Board', null, 'team');
        $column = $board->columns()->first();

        $this->actingAs($owner)
            ->post(route('projects.tasks.store', $board), ['column_id' => $column->id, 'title' => "\u{200B}\u{200C}"])
            ->assertSessionHasErrors('title');

        $this->assertDatabaseMissing('tasks', ['project_board_id' => $board->id]);
    }

    public function test_a_board_name_with_invisible_characters_is_stripped(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)
            ->post(route('projects.boards.store'), [
                'name' => "Launch\u{200B} plan", 'visibility' => 'team', 'accent' => 'blue',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('project_boards', ['name' => 'Launch plan']);
    }

    public function test_a_column_name_with_invisible_characters_is_stripped(): void
    {
        $owner = User::factory()->create();
        $board = app(ProjectBoards::class)->create($owner->current_team_id, $owner->id, 'Board', null, 'team');

        $this->actingAs($owner)
            ->post(route('projects.columns.store', $board), ['name' => "Re\u{200D}view"])
            ->assertRedirect();

        $this->assertDatabaseHas('project_columns', ['name' => 'Review']);
    }

    public function test_a_label_name_with_invisible_characters_is_stripped(): void
    {
        $owner = User::factory()->create();
        $board = app(ProjectBoards::class)->create($owner->current_team_id, $owner->id, 'Board', null, 'team');

        $this->actingAs($owner)
            ->post(route('projects.labels.store', $board), ['name' => "Urg\u{00AD}ent", 'color' => 'rose'])
            ->assertRedirect();

        $this->assertDatabaseHas('project_labels', ['name' => 'Urgent']);
    }
}
