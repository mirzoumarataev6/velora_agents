<?php

namespace Tests\Feature\Crm;

use App\Models\Customer;
use App\Models\Deal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Deal tags mirror the existing customer tags: free-typed, comma separated, filterable. */
class DealTagsTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_the_edit_form_parses_comma_separated_tags(): void
    {
        $owner = User::factory()->create();
        $customer = Customer::factory()->for($owner)->create();
        $deal = Deal::factory()->for($owner)->for($customer)->create();

        $this->actingAs($owner)->patchJson(route('crm.deals.update', $deal), [
            'title' => $deal->title,
            'amount' => 100,
            'payment_status' => 'unpaid',
            'tags' => 'VIP, повтор,  VIP ',
        ])->assertOk();

        $this->assertSame(['VIP', 'повтор'], $deal->fresh()->tags);
    }

    public function test_an_absent_tags_field_does_not_clear_existing_tags(): void
    {
        $owner = User::factory()->create();
        $deal = Deal::factory()->for($owner)->create(['tags' => ['VIP']]);

        $this->actingAs($owner)->patchJson(route('crm.deals.update', $deal), [
            'title' => $deal->title,
            'amount' => 100,
            'payment_status' => 'unpaid',
        ])->assertOk();

        $this->assertSame(['VIP'], $deal->fresh()->tags);
    }

    public function test_the_board_can_be_filtered_by_tag(): void
    {
        $owner = User::factory()->create();
        $tagged = Deal::factory()->for($owner)->create(['tags' => ['VIP']]);
        Deal::factory()->for($owner)->create(['tags' => ['regular']]);

        $response = $this->actingAs($owner)->getJson(route('crm.deals.poll', ['tag' => 'VIP']));

        $response->assertOk();
        $ids = collect($response->json('deals'))->pluck('id')->all();
        $this->assertContains($tagged->id, $ids);
        $this->assertCount(1, $ids);
    }

    public function test_tags_are_included_in_the_csv_export(): void
    {
        $owner = User::factory()->create();
        Deal::factory()->for($owner)->create(['title' => 'Экспортная сделка', 'tags' => ['VIP', 'повтор']]);

        $response = $this->actingAs($owner)->get(route('crm.deals.export'));

        $response->assertOk();
        $this->assertStringContainsString('VIP, повтор', $response->getContent());
    }

    public function test_tags_show_up_on_the_deal_card(): void
    {
        $owner = User::factory()->create();
        Deal::factory()->for($owner)->create(['tags' => ['VIP']]);

        $response = $this->actingAs($owner)->getJson(route('crm.deals.poll'));

        $response->assertOk();
        $this->assertSame(['VIP'], $response->json('deals.0.tags'));
    }
}
