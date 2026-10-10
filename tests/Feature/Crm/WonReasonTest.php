<?php

namespace Tests\Feature\Crm;

use App\Models\Customer;
use App\Models\Deal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WonReasonTest extends TestCase
{
    use RefreshDatabase;

    public function test_closing_a_paid_deal_records_an_optional_won_reason(): void
    {
        $owner = User::factory()->create();
        $customer = Customer::factory()->for($owner)->create();
        $deal = Deal::factory()->for($owner)->for($customer)->stage('payment')->create(['payment_status' => 'paid', 'amount' => 500]);

        $this->actingAs($owner)->patchJson(route('crm.deals.update', $deal), [
            'title' => $deal->title, 'amount' => $deal->amount, 'payment_status' => 'paid',
            'sales_origin' => 'social', 'closed' => true, 'won_reason' => 'Постоянный клиент, скидка не нужна',
        ])->assertOk();

        $deal->refresh();
        $this->assertNotNull($deal->closed_at);
        $this->assertSame('Постоянный клиент, скидка не нужна', $deal->won_reason);

        // Mirrors timeline_cancelled's shape — its own activity type, not
        // folded into the generic "updated" bucket.
        $activity = $deal->activities()->where('type', 'closed')->sole();
        $this->assertSame('Постоянный клиент, скидка не нужна', $activity->data['reason']);
    }

    public function test_reopening_a_deal_clears_the_won_reason(): void
    {
        $owner = User::factory()->create();
        $customer = Customer::factory()->for($owner)->create();
        $deal = Deal::factory()->for($owner)->for($customer)->stage('payment')->create([
            'payment_status' => 'paid', 'amount' => 500, 'closed_at' => now(), 'won_reason' => 'Старая причина',
        ]);

        $this->actingAs($owner)->patchJson(route('crm.deals.update', $deal), [
            'title' => $deal->title, 'amount' => $deal->amount, 'payment_status' => 'paid',
            'sales_origin' => 'social', 'closed' => false,
        ])->assertOk();

        $deal->refresh();
        $this->assertNull($deal->closed_at);
        $this->assertNull($deal->won_reason);
    }
}
