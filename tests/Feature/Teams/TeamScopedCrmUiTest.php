<?php

namespace Tests\Feature\Teams;

use App\Models\CrmWorkspace;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stage 2d-4-2: the UI call sites that validate against / create CRM rows —
 * `Rule::exists`/`Rule::unique`, `CrmWorkspace::defaultFor()`,
 * `CrmStage::defaultsFor()`/`initialFor()` — now resolve against the
 * signed-in owner's ACTIVE team (`User::activeTeamId()`) instead of their
 * `user_id`. Two things that proves, together:
 *
 * 1. A record from another team is now a hard validation rejection, not a
 *    silent cross-team attach — the composite FKs added in Stage 2d-3-2
 *    would otherwise turn that into a 500 (an FK violation) instead of a
 *    422 the owner can actually act on.
 * 2. Every row these controllers create carries `team_id` explicitly, so
 *    it is never left to StampsTeam's own `current_team_id` fallback —
 *    which matters the moment `current_team_id` and the team just
 *    validated against can differ (not true yet for any single-team
 *    account, but the explicit write is what keeps it true once a second
 *    team exists).
 */
class TeamScopedCrmUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_deal_store_rejects_a_workspace_belonging_to_another_team(): void
    {
        $owner = User::factory()->create();
        $customer = Customer::forceCreate([
            'user_id' => $owner->id,
            'crm_workspace_id' => CrmWorkspace::defaultFor($owner)->id,
            'name' => 'Azim',
            'status' => 'lead',
        ]);

        $otherTeam = Team::factory()->create();
        $foreignWorkspace = CrmWorkspace::withoutTeamStamping(fn () => CrmWorkspace::forceCreate([
            'user_id' => $owner->id,
            'team_id' => $otherTeam->id,
            'name' => 'Someone else’s folder',
            'color' => 'blue',
        ]));

        $this->actingAs($owner)->post(route('crm.deals.store'), [
            'customer_id' => $customer->id,
            'title' => 'A sale',
            'amount' => 100,
            'crm_workspace_id' => $foreignWorkspace->id,
        ])->assertSessionHasErrors('crm_workspace_id');

        $this->assertSame(0, Deal::count());
    }

    public function test_deal_created_by_hand_carries_the_active_team_id_explicitly(): void
    {
        $owner = User::factory()->create();
        $customer = Customer::forceCreate([
            'user_id' => $owner->id,
            'crm_workspace_id' => CrmWorkspace::defaultFor($owner)->id,
            'name' => 'Azim',
            'status' => 'lead',
        ]);

        $this->actingAs($owner)->post(route('crm.deals.store'), [
            'customer_id' => $customer->id,
            'title' => 'A sale',
            'amount' => 100,
        ])->assertRedirect();

        $deal = Deal::sole();
        $this->assertSame($owner->activeTeamId(), $deal->team_id);
    }

    public function test_workspace_created_by_hand_carries_the_active_team_id_explicitly(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)->post(route('crm.workspaces.store'), [
            'name' => 'Khujand store',
            'color' => 'violet',
        ])->assertRedirect();

        $workspace = CrmWorkspace::where('is_default', false)->sole();
        $this->assertSame($owner->activeTeamId(), $workspace->team_id);
    }

    public function test_customer_created_by_hand_carries_the_active_team_id_explicitly(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)->post(route('crm.customers.store'), [
            'name' => 'Azim',
        ])->assertRedirect();

        $customer = Customer::sole();
        $this->assertSame($owner->activeTeamId(), $customer->team_id);
    }

    public function test_task_created_by_hand_carries_the_active_team_id_explicitly(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)->post(route('crm.tasks.store'), [
            'title' => 'Follow up',
            'new_customer_name' => 'Azim',
        ])->assertRedirect();

        $task = Task::sole();
        $this->assertSame($owner->activeTeamId(), $task->team_id);
    }
}
