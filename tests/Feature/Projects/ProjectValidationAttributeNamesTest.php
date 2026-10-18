<?php

namespace Tests\Feature\Projects;

use App\Models\Task;
use App\Models\User;
use App\Services\Projects\ProjectBoards;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PHASE 2 item 9: a validation error for a field with no `attributes` entry
 * reads as a raw field name ("The customer_id field is required.") — in
 * English, regardless of the site's locale, since Laravel's own rule
 * templates only ship in English and this app's ru/tg files never defined
 * it either. Confirms the actual, observable effect of the new
 * validation.attributes entries: an owner validating a Projects form in
 * Russian sees a Russian word, not "customer_id" or an English sentence.
 */
class ProjectValidationAttributeNamesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_missing_required_task_field_names_itself_in_russian_not_as_a_raw_field_name(): void
    {
        $owner = User::factory()->create(['locale' => 'ru']);
        $board = app(ProjectBoards::class)->create($owner->current_team_id, $owner->id, 'Board', null, 'team');
        $column = $board->columns()->first();

        $response = $this->actingAs($owner)
            ->post(route('projects.tasks.store', $board), ['column_id' => $column->id]);

        $response->assertSessionHasErrors('title');
        $errors = $response->getSession()->get('errors')->getBag('default');
        $this->assertStringNotContainsString('title field', $errors->first('title'));
        $this->assertStringContainsString('название', $errors->first('title'));
    }

    public function test_an_out_of_range_wip_limit_names_itself_in_russian_not_as_a_raw_field_name(): void
    {
        $owner = User::factory()->create(['locale' => 'ru']);
        $board = app(ProjectBoards::class)->create($owner->current_team_id, $owner->id, 'Board', null, 'team');

        $response = $this->actingAs($owner)
            ->post(route('projects.columns.store', $board), ['name' => 'Huge', 'wip_limit' => 99999]);

        $response->assertSessionHasErrors('wip_limit');
        $errors = $response->getSession()->get('errors')->getBag('default');
        $this->assertStringNotContainsString('wip_limit', $errors->first('wip_limit'));
        $this->assertStringContainsString('лимит задач в работе', $errors->first('wip_limit'));
    }

    public function test_an_invalid_assignee_id_names_itself_in_russian_not_as_a_raw_field_name(): void
    {
        $owner = User::factory()->create(['locale' => 'ru']);
        $board = app(ProjectBoards::class)->create($owner->current_team_id, $owner->id, 'Board', null, 'team');
        $column = $board->columns()->first();
        $task = Task::factory()->for($owner)->create(['project_board_id' => $board->id, 'project_column_id' => $column->id]);

        $response = $this->actingAs($owner)
            ->patch(route('projects.tasks.update', [$board, $task]), [
                'assignees_present' => 1, 'assignee_ids' => [999999],
            ]);

        $response->assertSessionHasErrors('assignee_ids.0');
        $errors = $response->getSession()->get('errors')->getBag('default');
        $this->assertStringNotContainsString('assignee_ids', $errors->first('assignee_ids.0'));
        $this->assertStringContainsString('исполнитель', $errors->first('assignee_ids.0'));
    }
}
