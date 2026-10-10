<?php

namespace Tests\Feature\Crm;

use App\Models\Agent;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\CrmStage;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\User;
use App\Services\Crm\DealBoard;
use App\Services\Haggling\HagglingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The invariant the whole configurable-pipeline rewrite depends on:
 * `deals.stage` (name), `deals.stage_role` and `deals.crm_stage_id` can
 * never disagree — and the payment-integrity guard, which now reads
 * `stage_role` instead of the literal 'payment' string, still refuses a
 * paid deal that isn't actually sitting in the WON-role stage.
 */
class CrmStageSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_moving_a_deal_keeps_stage_name_role_and_id_in_agreement(): void
    {
        $owner = User::factory()->create();
        $customer = Customer::factory()->for($owner)->create();
        $deal = Deal::factory()->for($owner)->for($customer)->create();
        $stages = CrmStage::defaultsFor($owner);

        foreach (['haggling', 'payment', 'cancelled', 'new_lead'] as $name) {
            $target = $stages->firstWhere('name', $name);
            app(DealBoard::class)->move($deal, $target, byOwner: true, cancelledReason: $name === 'cancelled' ? 'Не подошла цена' : null);
            $deal->refresh();

            $this->assertSame($target->id, $deal->crm_stage_id, "crm_stage_id mismatch moving to {$name}");
            $this->assertSame($target->name, $deal->stage, "stage mismatch moving to {$name}");
            $this->assertSame($target->role, $deal->stage_role, "stage_role mismatch moving to {$name}");
        }
    }

    public function test_payment_status_paid_is_rejected_unless_the_deal_is_in_the_won_stage(): void
    {
        $owner = User::factory()->create();
        $customer = Customer::factory()->for($owner)->create();
        $deal = Deal::factory()->for($owner)->for($customer)->stage('haggling')->create();

        // BEFORE: sitting in an intermediate (non-WON) stage — rejected.
        $this->actingAs($owner)->patchJson(route('crm.deals.update', $deal), [
            'title' => $deal->title,
            'amount' => 100,
            'payment_status' => 'paid',
            'sales_origin' => 'social',
        ])->assertJsonValidationErrors('payment_status');

        $this->assertSame('unpaid', $deal->fresh()->payment_status);

        // AFTER: the same request also moves the deal into the WON stage in
        // the same save — now it's allowed, because by the time payment_status
        // is evaluated the deal really is there.
        $this->actingAs($owner)->patchJson(route('crm.deals.update', $deal), [
            'title' => $deal->title,
            'amount' => 100,
            'payment_status' => 'paid',
            'sales_origin' => 'social',
            'stage' => 'payment',
        ])->assertOk();

        $deal->refresh();
        $this->assertSame('paid', $deal->payment_status);
        $this->assertSame('won', $deal->stage_role);
    }

    private function conversation(?User $owner = null): Conversation
    {
        $agent = Agent::factory()->for($owner ?? User::factory())->create();

        return Conversation::factory()->for($agent)
            ->for(Channel::factory()->for($agent)->create(['type' => 'telegram']))
            ->create();
    }

    /** The default, un-customized pipeline always has exactly one intermediate stage — automation behaves exactly as before. */
    public function test_negotiation_signal_still_advances_automatically_with_exactly_one_intermediate_stage(): void
    {
        $conversation = $this->conversation();

        Lead::factory()->for($conversation->agent)->create([
            'conversation_id' => $conversation->id,
            'type' => HagglingService::TYPE,
            'status' => HagglingService::STATUS_PENDING,
        ]);

        $this->assertSame('haggling', Deal::sole()->stage);
    }

    /** With a second custom intermediate stage, the signal no longer has one unambiguous target — it stops moving the card. */
    public function test_negotiation_signal_stops_advancing_once_a_second_intermediate_stage_exists(): void
    {
        $conversation = $this->conversation();
        $owner = $conversation->agent->user;

        // Insert a second intermediate between the default two.
        CrmStage::forceCreate([
            'user_id' => $owner->id,
            'name' => 'Переговоры',
            'role' => null,
            'position' => 2,
            'color' => 'blue',
            'is_system' => false,
        ]);

        $deal = Deal::sole();
        $this->assertSame('new_lead', $deal->stage);

        Lead::factory()->for($conversation->agent)->create([
            'conversation_id' => $conversation->id,
            'type' => HagglingService::TYPE,
            'status' => HagglingService::STATUS_PENDING,
        ]);

        $this->assertSame('new_lead', $deal->fresh()->stage);
    }
}
