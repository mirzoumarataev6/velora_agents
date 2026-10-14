<?php

namespace Tests\Feature\Projects;

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Role;
use App\Models\Task;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Permissions\PermissionCatalog;
use App\Services\Projects\ProjectBoards;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectPrivacyRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_crm_can_show_a_team_board_card_without_granting_project_edit_rights(): void
    {
        $owner = User::factory()->create();
        $operator = User::factory()->create();
        $role = Role::where('team_id', $owner->current_team_id)->where('slug', 'operator')->sole();
        $role->syncPermissions([
            PermissionCatalog::ANALYTICS_VIEW, PermissionCatalog::ANALYTICS_MANAGE,
            PermissionCatalog::PROJECTS_VIEW,
        ]);
        TeamMembership::create([
            'team_id' => $owner->current_team_id,
            'user_id' => $operator->id,
            'role_id' => $role->id,
            'joined_at' => now(),
        ]);
        $operator->forceFill(['current_team_id' => $owner->current_team_id])->save();

        $board = app(ProjectBoards::class)->create($owner->current_team_id, $owner->id, 'Shared');
        $task = Task::factory()->for($owner)->create([
            'project_board_id' => $board->id,
            'project_column_id' => $board->columns()->first()->id,
            'title' => 'Shared project card',
        ]);

        $this->actingAs($operator)->get(route('crm.tasks.index'))->assertOk()->assertSee($task->title);
        $this->patch(route('crm.tasks.update', $task), ['title' => 'Changed'])->assertForbidden();
        $this->delete(route('crm.tasks.destroy', $task))->assertForbidden();
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'title' => $task->title, 'deleted_at' => null]);
    }

    public function test_crm_routes_and_related_views_do_not_expose_a_private_board(): void
    {
        $owner = User::factory()->create();
        $operator = User::factory()->create();
        $role = Role::where('team_id', $owner->current_team_id)->where('slug', 'operator')->sole();
        $role->syncPermissions([
            PermissionCatalog::ANALYTICS_VIEW, PermissionCatalog::ANALYTICS_MANAGE,
            PermissionCatalog::PROJECTS_VIEW, PermissionCatalog::PROJECTS_EDIT,
        ]);
        TeamMembership::create([
            'team_id' => $owner->current_team_id,
            'user_id' => $operator->id,
            'role_id' => $role->id,
            'joined_at' => now(),
        ]);
        $operator->forceFill(['current_team_id' => $owner->current_team_id])->save();

        $customer = Customer::factory()->for($owner)->create();
        $deal = Deal::factory()->for($owner)->for($customer)->create();
        $board = app(ProjectBoards::class)->create($owner->current_team_id, $owner->id, 'Private', null, 'private');
        $task = Task::factory()->for($owner)->create([
            'project_board_id' => $board->id,
            'project_column_id' => $board->columns()->first()->id,
            'customer_id' => $customer->id,
            'deal_id' => $deal->id,
            'title' => 'Confidential project card',
        ]);

        $this->actingAs($operator)->get(route('crm.tasks.index'))->assertOk()->assertDontSee($task->title);
        $this->get(route('crm.customers.show', $customer))->assertOk()->assertDontSee($task->title);
        $this->get(route('crm.deals.activity', $deal))->assertOk()->assertDontSee($task->title);
        $this->get(route('crm.board', ['deal' => $deal->id]))->assertOk()->assertDontSee($task->title);
        $this->patch(route('crm.tasks.update', $task), ['title' => 'Changed'])->assertForbidden();
        $this->delete(route('crm.tasks.destroy', $task))->assertForbidden();
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'title' => $task->title, 'deleted_at' => null]);
    }
}
