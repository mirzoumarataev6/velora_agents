<?php

namespace Tests\Feature\Crm;

use App\Models\Customer;
use App\Models\CustomerMerge;
use App\Models\Deal;
use App\Models\Task;
use App\Models\User;
use App\Services\Crm\CustomerResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerMergeUndoTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The one thing this action exists to guarantee: the absorbed profile
     * comes back, and exactly its own rows — not whatever `into` already
     * had before the merge — move with it.
     */
    public function test_undoing_a_merge_restores_the_profile_and_only_its_own_rows(): void
    {
        $owner = User::factory()->create();
        // $keep already has its OWN number, so the merge never adopts
        // $absorb's — leaving it free for undo to give back. If $keep had
        // absorbed the number instead, undo deliberately leaves it there
        // (see CustomerResolver::undoMerge()'s own docblock on why) and that
        // is a different case, not this one.
        $keep = Customer::factory()->for($owner)->create(['phone' => '+992911111111', 'phone_key' => '911111111']);
        $absorb = Customer::factory()->for($owner)->create(['phone' => '+992900112233', 'phone_key' => '900112233']);

        // `keep` already has its own deal — merging must never let this move.
        $keepsOwnDeal = Deal::factory()->for($owner)->for($keep)->create();
        $absorbedDeal = Deal::factory()->for($owner)->for($absorb)->create();
        $absorbedTask = Task::factory()->for($owner)->for($absorb)->create();

        $this->actingAs($owner)
            ->post(route('crm.customers.merge', $keep), ['customer_id' => $absorb->id])
            ->assertRedirect();

        $this->assertSame($keep->id, $absorbedDeal->fresh()->customer_id);
        $merge = CustomerMerge::where('into_customer_id', $keep->id)->sole();

        $this->actingAs($owner)
            ->post(route('crm.customers.merge.undo', ['customer' => $keep, 'merge' => $merge]))
            ->assertRedirect(route('crm.customers.show', $keep));

        $this->assertNotSoftDeleted($absorb);
        $this->assertSame($absorb->id, $absorbedDeal->fresh()->customer_id);
        $this->assertSame($absorb->id, $absorbedTask->fresh()->customer_id);
        // Never touched by the merge or its undo.
        $this->assertSame($keep->id, $keepsOwnDeal->fresh()->customer_id);
        $this->assertSame('900112233', $absorb->fresh()->phone_key);
        $this->assertNotNull($merge->fresh()->undone_at);
    }

    public function test_undo_is_refused_once_the_window_has_passed(): void
    {
        $owner = User::factory()->create();
        $keep = Customer::factory()->for($owner)->create();
        $absorb = Customer::factory()->for($owner)->create();

        app(CustomerResolver::class)->merge(from: $absorb, into: $keep);
        $merge = CustomerMerge::where('into_customer_id', $keep->id)->sole();
        $merge->forceFill(['created_at' => now()->subHours(25)])->save();

        $this->actingAs($owner)
            ->post(route('crm.customers.merge.undo', ['customer' => $keep, 'merge' => $merge]))
            ->assertRedirect(route('crm.customers.show', $keep));

        $this->assertSoftDeleted($absorb);
        $this->assertNull($merge->fresh()->undone_at);
    }

    public function test_undo_is_refused_for_another_owners_merge(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $keep = Customer::factory()->for($other)->create();
        $absorb = Customer::factory()->for($other)->create();
        app(CustomerResolver::class)->merge(from: $absorb, into: $keep);
        $merge = CustomerMerge::where('into_customer_id', $keep->id)->sole();

        $this->actingAs($owner)
            ->post(route('crm.customers.merge.undo', ['customer' => $keep, 'merge' => $merge]))
            ->assertForbidden();
    }
}
