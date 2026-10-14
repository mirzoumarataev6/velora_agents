<?php

namespace Tests\Feature\Projects;

use App\Models\Task;
use App\Models\User;
use App\Services\Projects\ProjectBoards;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_crm_task_writes_land_on_the_same_default_board(): void
    {
        $owner = User::factory()->create();
        $first = Task::factory()->for($owner)->create(['title' => 'Call customer']);
        $second = Task::factory()->for($owner)->done()->create(['title' => 'Send offer']);

        $this->assertSame($first->project_board_id, $second->project_board_id);
        $this->assertNotNull($first->project_column_id);
        $this->assertNotSame($first->project_column_id, $second->project_column_id);
        $this->assertFalse($first->projectColumn->is_done);
        $this->assertTrue($second->projectColumn->is_done);
        $this->assertSame('_inbox', $first->projectBoard->slug);
    }

    public function test_the_database_rejects_a_task_on_a_column_from_another_board(): void
    {
        $owner = User::factory()->create();
        $boards = app(ProjectBoards::class);
        $first = $boards->create($owner->current_team_id, $owner->id, 'First');
        $second = $boards->create($owner->current_team_id, $owner->id, 'Second');

        $this->expectException(QueryException::class);
        Task::factory()->for($owner)->create([
            'project_board_id' => $first->id,
            'project_column_id' => $second->columns()->firstOrFail()->id,
        ]);
    }
}
