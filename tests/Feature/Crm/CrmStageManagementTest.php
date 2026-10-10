<?php

namespace Tests\Feature\Crm;

use App\Models\CrmStage;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Add/rename/recolour/reorder/delete-with-reassignment, and the role invariants none of it can get around. */
class CrmStageManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_stages_page_lists_the_default_pipeline(): void
    {
        $owner = User::factory()->create();

        $response = $this->actingAs($owner)->get(route('crm.stages.index'));

        $response->assertOk();
        $response->assertSee(__('crm.stages.new_lead'));
        $response->assertSee(__('crm.stages.haggling'));
        $response->assertSee(__('crm.stages.payment'));
        $response->assertSee(__('crm.stages.cancelled'));
    }

    public function test_the_owner_can_add_an_intermediate_stage(): void
    {
        $owner = User::factory()->create();
        CrmStage::defaultsFor($owner);

        $this->actingAs($owner)->post(route('crm.stages.store'), [
            'name' => 'Переговоры',
            'color' => 'blue',
        ])->assertRedirect();

        $stage = CrmStage::ownedBy($owner)->where('name', 'Переговоры')->sole();
        $this->assertNull($stage->role);
        $this->assertFalse($stage->is_system);

        // Inserted right before the won stage, which was bumped back by one.
        $won = CrmStage::ownedBy($owner)->where('role', 'won')->sole();
        $this->assertSame($won->position - 1, $stage->position);
    }

    public function test_renaming_a_system_stage_makes_it_literal_and_no_longer_translated(): void
    {
        $owner = User::factory()->create();
        $haggling = CrmStage::defaultsFor($owner)->firstWhere('name', 'haggling');

        $this->actingAs($owner)->patch(route('crm.stages.update', $haggling), [
            'name' => 'Переговоры 2',
            'color' => 'blue',
        ])->assertRedirect();

        $haggling->refresh();
        $this->assertFalse($haggling->is_system);
        $this->assertSame('Переговоры 2', $haggling->displayName());
        $this->assertSame('blue', $haggling->color);
    }

    /** #[Fillable] already blocks a normal update(['role' => ...]) — this exercises the deeper guard against a forceFill() bypass. */
    public function test_a_stages_role_cannot_be_changed_even_via_forcefill(): void
    {
        $owner = User::factory()->create();
        $won = CrmStage::defaultsFor($owner)->firstWhere('role', 'won');

        $this->expectException(ValidationException::class);
        $won->forceFill(['role' => null])->save();
    }

    public function test_a_second_stage_cannot_claim_an_already_taken_role(): void
    {
        $owner = User::factory()->create();
        CrmStage::defaultsFor($owner);

        $this->expectException(ValidationException::class);
        CrmStage::forceCreate(['user_id' => $owner->id, 'name' => 'Duplicate won', 'role' => 'won', 'position' => 99, 'color' => 'blue']);
    }

    public function test_a_role_bearing_stage_cannot_be_deleted(): void
    {
        $owner = User::factory()->create();
        $won = CrmStage::defaultsFor($owner)->firstWhere('role', 'won');

        $this->actingAs($owner)->delete(route('crm.stages.destroy', $won))->assertForbidden();
        $this->assertNotSoftDeleted($won);
    }

    public function test_deleting_an_empty_intermediate_stage_needs_no_reassignment(): void
    {
        $owner = User::factory()->create();
        $stages = CrmStage::defaultsFor($owner);
        $haggling = $stages->firstWhere('name', 'haggling');

        $this->actingAs($owner)->delete(route('crm.stages.destroy', $haggling))->assertRedirect();

        $this->assertSoftDeleted($haggling);
    }

    public function test_deleting_a_stage_with_deals_requires_picking_a_destination(): void
    {
        $owner = User::factory()->create();
        $customer = Customer::factory()->for($owner)->create();
        $stages = CrmStage::defaultsFor($owner);
        $haggling = $stages->firstWhere('name', 'haggling');
        $deal = Deal::factory()->for($owner)->for($customer)->stage('haggling')->create();

        $this->actingAs($owner)->delete(route('crm.stages.destroy', $haggling))
            ->assertRedirect()->assertSessionHasErrors('reassign_to');

        $this->assertNotSoftDeleted($haggling);
        $this->assertSame('haggling', $deal->fresh()->stage);
    }

    public function test_deleting_a_stage_with_deals_reassigns_them_in_one_transaction(): void
    {
        $owner = User::factory()->create();
        $customer = Customer::factory()->for($owner)->create();
        $stages = CrmStage::defaultsFor($owner);
        $haggling = $stages->firstWhere('name', 'haggling');
        $newLead = $stages->firstWhere('name', 'new_lead');
        $deal = Deal::factory()->for($owner)->for($customer)->stage('haggling')->create();

        $this->actingAs($owner)->delete(route('crm.stages.destroy', $haggling), [
            'reassign_to' => $newLead->id,
        ])->assertRedirect();

        $this->assertSoftDeleted($haggling);
        $deal->refresh();
        $this->assertSame($newLead->id, $deal->crm_stage_id);
        $this->assertSame('new_lead', $deal->stage);
        $this->assertSame('initial', $deal->stage_role);
    }

    public function test_a_different_owners_stage_cannot_be_managed(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $stage = CrmStage::defaultsFor($owner)->firstWhere('name', 'haggling');

        $this->actingAs($other)->patch(route('crm.stages.update', $stage), [
            'name' => 'Hijacked',
            'color' => 'blue',
        ])->assertForbidden();
    }

    public function test_reordering_two_intermediate_stages(): void
    {
        $owner = User::factory()->create();
        $stages = CrmStage::defaultsFor($owner);
        $haggling = $stages->firstWhere('name', 'haggling');
        $second = CrmStage::forceCreate([
            'user_id' => $owner->id, 'name' => 'Второй', 'role' => null, 'position' => 2, 'color' => 'blue',
        ]);
        CrmStage::ownedBy($owner)->where('role', 'won')->update(['position' => 3]);

        $this->actingAs($owner)->post(route('crm.stages.reorder'), [
            'order' => [$second->id, $haggling->id],
        ])->assertRedirect();

        $this->assertSame(1, $second->fresh()->position);
        $this->assertSame(2, $haggling->fresh()->position);
    }
}
