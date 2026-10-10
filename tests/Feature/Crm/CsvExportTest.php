<?php

namespace Tests\Feature\Crm;

use App\Models\Customer;
use App\Models\Deal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The directory and the board, exported exactly as filtered.
 */
class CsvExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_export_is_utf8_with_a_bom_and_respects_filters(): void
    {
        $owner = User::factory()->create();
        Customer::factory()->for($owner)->create(['name' => 'Азиз', 'status' => 'customer']);
        Customer::factory()->for($owner)->create(['name' => 'Фарид', 'status' => 'lead']);
        // Another owner's book must never leak into this export.
        Customer::factory()->create(['name' => 'Чужой']);

        $response = $this->actingAs($owner)->get(route('crm.customers.export', ['status' => 'customer']));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $body = $response->getContent();

        $this->assertStringStartsWith("\u{FEFF}", $body);
        $this->assertStringContainsString('Азиз', $body);
        $this->assertStringNotContainsString('Фарид', $body);
        $this->assertStringNotContainsString('Чужой', $body);
    }

    public function test_deal_export_lists_every_matching_deal_not_one_per_customer(): void
    {
        $owner = User::factory()->create();
        $customer = Customer::factory()->for($owner)->create(['name' => 'Азиз']);
        Deal::factory()->for($owner)->for($customer)->create(['title' => 'Первая покупка']);
        Deal::factory()->for($owner)->for($customer)->create(['title' => 'Вторая покупка']);

        $response = $this->actingAs($owner)->get(route('crm.deals.export'));

        $response->assertOk();
        $body = $response->getContent();
        // The board groups repeat customers onto one card — the export must
        // not inherit that grouping, or a repeat buyer's history would be
        // invisible in their own spreadsheet export.
        $this->assertStringContainsString('Первая покупка', $body);
        $this->assertStringContainsString('Вторая покупка', $body);
    }

    public function test_export_requires_being_signed_in(): void
    {
        $this->get(route('crm.customers.export'))->assertRedirect();
    }
}
