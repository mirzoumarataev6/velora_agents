<?php

namespace Tests\Feature\Crm;

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Deal, Customer, Task and CrmWorkspace are recoverable now — deleting one
 * sets deleted_at rather than removing the row, and the things that must
 * keep working around that (activity history, the customer phone-number
 * unique index) are pinned here directly.
 */
class SoftDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_a_deal_soft_deletes_it_and_keeps_its_activity_history(): void
    {
        $owner = User::factory()->create();
        $customer = Customer::factory()->for($owner)->create();
        $deal = Deal::factory()->for($owner)->for($customer)->create();
        // created() already logged one activity; this is the row the
        // cascadeOnDelete FK would remove on a real DELETE.
        $this->assertDatabaseCount('deal_activities', 1);

        $this->actingAs($owner)->delete(route('crm.deals.destroy', $deal))->assertRedirect();

        $this->assertSoftDeleted($deal);
        $this->assertDatabaseCount('deal_activities', 1);
        $this->assertSame(0, Deal::count());
        $this->assertSame(1, Deal::withTrashed()->count());
    }

    public function test_deleting_a_task_soft_deletes_it(): void
    {
        $owner = User::factory()->create();
        $task = Task::factory()->for($owner)->create();

        $this->actingAs($owner)->delete(route('crm.tasks.destroy', $task))->assertRedirect();

        $this->assertSoftDeleted($task);
        $this->assertSame(0, Task::count());
    }

    /**
     * The merge invariant this exists for: a soft-deleted profile's number
     * is free again immediately, not just after the retention window.
     *
     * `$keep` already has its OWN number here, so the merge never adopts
     * $absorb's — leaving it genuinely unclaimed by anyone afterward, rather
     * than migrated to the survivor (which would legitimately keep it, and
     * is a different, equally real case covered by CustomerMergeUndoTest).
     */
    public function test_a_merged_away_customers_phone_number_can_be_reused_immediately(): void
    {
        $owner = User::factory()->create();
        $keep = Customer::factory()->for($owner)->create(['phone' => '+992911111111', 'phone_key' => '911111111']);
        $absorb = Customer::factory()->for($owner)->create(['phone' => '+992900112233', 'phone_key' => '900112233']);

        $this->actingAs($owner)
            ->post(route('crm.customers.merge', $keep), ['customer_id' => $absorb->id])
            ->assertRedirect();

        $this->assertSoftDeleted($absorb);
        $this->assertNull($absorb->fresh()->phone_key);
        // Unchanged: $keep already had its own number, so the merge never
        // adopted $absorb's.
        $this->assertSame('911111111', $keep->fresh()->phone_key);

        // A brand-new customer claiming the exact same number must not hit
        // the (user_id, phone_key) unique index the trashed row still sits in.
        $new = Customer::forceCreate([
            'user_id' => $owner->id,
            'name' => 'Новый',
            'phone' => '+992900112233',
            'phone_key' => '900112233',
            'status' => 'lead',
        ]);

        $this->assertNotNull($new->id);
        $this->assertSame('900112233', $new->phone_key);
    }
}
