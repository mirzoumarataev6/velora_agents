<?php

namespace Tests\Feature\Services\Account;

use App\Models\Agent;
use App\Models\CrmStage;
use App\Models\CrmWorkspace;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\User;
use App\Services\Account\DeletesAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * DeletesAccount exists because a plain $user->delete() trips MySQL's
 * cascade order against the composite RESTRICT foreign keys deals/customers
 * hold against crm_stages/crm_workspaces — see that service's own docblock.
 * These tests don't reproduce the MySQL failure directly (SQLite's current
 * table order happens not to trip it either way); they prove the service
 * does what it claims: deals/customers are physically gone, including
 * already-soft-deleted ones, before anything else is touched.
 */
class DeletesAccountTest extends TestCase
{
    use RefreshDatabase;

    private function fullAccount(): array
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();
        $workspace = CrmWorkspace::defaultFor($user);
        $stage = CrmStage::initialFor($user);
        $customer = Customer::factory()->for($user)->create(['crm_workspace_id' => $workspace->id]);
        $deal = Deal::factory()->for($user)->create([
            'customer_id' => $customer->id,
            'crm_workspace_id' => $workspace->id,
            'crm_stage_id' => $stage->id,
            'agent_id' => $agent->id,
        ]);

        return compact('user', 'agent', 'workspace', 'stage', 'customer', 'deal');
    }

    public function test_it_deletes_the_user_and_everything_hanging_off_it(): void
    {
        $fixture = $this->fullAccount();

        app(DeletesAccount::class)->delete($fixture['user']);

        $this->assertDatabaseMissing('users', ['id' => $fixture['user']->id]);
        $this->assertDatabaseMissing('agents', ['id' => $fixture['agent']->id]);
        $this->assertDatabaseMissing('deals', ['id' => $fixture['deal']->id]);
        $this->assertDatabaseMissing('customers', ['id' => $fixture['customer']->id]);
        $this->assertDatabaseMissing('crm_workspaces', ['id' => $fixture['workspace']->id]);
        $this->assertDatabaseMissing('crm_stages', ['id' => $fixture['stage']->id]);
    }

    /**
     * The precedent-1/Stage-2d-2 regression, now driven through the real
     * controller endpoint rather than the service directly.
     */
    public function test_the_http_endpoint_still_deletes_a_full_account(): void
    {
        $fixture = $this->fullAccount();
        $user = $fixture['user'];

        $this->actingAs($user)
            ->delete('/settings/account', ['confirmation' => 'DELETE'])
            ->assertRedirect('/');

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('deals', ['id' => $fixture['deal']->id]);
        $this->assertDatabaseMissing('customers', ['id' => $fixture['customer']->id]);
    }

    /**
     * forceDelete(), not delete(): a deal/customer already soft-deleted
     * before the account is removed still holds its crm_stage_id/
     * crm_workspace_id — a plain delete() would leave the (already
     * soft-deleted) row, and its foreign keys, exactly where they are.
     */
    public function test_already_soft_deleted_deals_and_customers_are_also_physically_removed(): void
    {
        $fixture = $this->fullAccount();
        $fixture['deal']->delete();
        $fixture['customer']->delete();

        $this->assertSoftDeleted('deals', ['id' => $fixture['deal']->id]);
        $this->assertSoftDeleted('customers', ['id' => $fixture['customer']->id]);

        app(DeletesAccount::class)->delete($fixture['user']);

        // forceDelete() bypasses the soft-delete scope entirely, so this
        // must be gone from the table, not merely still soft-deleted.
        $this->assertSame(0, DB::table('deals')->where('id', $fixture['deal']->id)->count());
        $this->assertSame(0, DB::table('customers')->where('id', $fixture['customer']->id)->count());
        $this->assertDatabaseMissing('users', ['id' => $fixture['user']->id]);
    }

    public function test_it_never_touches_another_users_rows(): void
    {
        $fixture = $this->fullAccount();
        $other = $this->fullAccount();

        app(DeletesAccount::class)->delete($fixture['user']);

        $this->assertDatabaseHas('users', ['id' => $other['user']->id]);
        $this->assertDatabaseHas('deals', ['id' => $other['deal']->id]);
        $this->assertDatabaseHas('customers', ['id' => $other['customer']->id]);
        $this->assertDatabaseHas('crm_workspaces', ['id' => $other['workspace']->id]);
        $this->assertDatabaseHas('crm_stages', ['id' => $other['stage']->id]);
    }
}
