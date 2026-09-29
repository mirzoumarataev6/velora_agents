<?php

namespace Tests\Feature\Crm;

use App\Models\Customer;
use App\Models\Deal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The "Новая сделка" modal: an existing customer, a brand-new one opened in
 * the same request, the now-required product/amount fields, and everything
 * that must NOT be allowed to happen — an empty submission creating a deal,
 * or a phone number opening a duplicate profile.
 */
class DealCreateFormTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The bug: the dropdown had no blank option, so the browser always
     * submitted whichever customer sorted first — an "empty" form was never
     * actually empty. Now nothing is pre-selected, so submitting without
     * choosing IS rejected, and rejected means no deal and no success toast.
     */
    public function test_submitting_with_no_customer_selected_is_rejected_and_creates_nothing(): void
    {
        $owner = User::factory()->create();
        Customer::factory()->for($owner)->create();

        $response = $this->actingAs($owner)->post(route('crm.deals.store'), [
            'customer_id' => '',
            'title' => 'Ковёр 3х4',
            'amount' => '2500',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('customer_id');

        $this->assertSame(0, Deal::count());
    }

    public function test_a_completely_empty_submission_is_rejected_with_no_deal_and_no_toast(): void
    {
        $owner = User::factory()->create();
        Customer::factory()->for($owner)->create();

        $response = $this->actingAs($owner)->from(route('crm.board'))
            ->post(route('crm.deals.store'), []);

        $response->assertRedirect(route('crm.board'));
        $response->assertSessionHasErrors(['customer_id', 'title', 'amount']);
        $response->assertSessionMissing('status');

        $this->assertSame(0, Deal::count());
    }

    /** Selecting "+ Новый клиент" without a name is rejected the same way. */
    public function test_new_customer_mode_without_a_name_is_rejected(): void
    {
        $owner = User::factory()->create();

        $response = $this->actingAs($owner)->post(route('crm.deals.store'), [
            'customer_id' => '__new__',
            'title' => 'Ковёр',
            'amount' => '100',
        ]);

        $response->assertSessionHasErrors('new_customer_name');
        $this->assertSame(0, Deal::count());
        $this->assertSame(0, Customer::count());
    }

    /**
     * Requirement #1: "Что покупают" and "Сумма" are required alongside the
     * customer, with a customer already validly chosen — isolates the two
     * new rules from the "nothing chosen at all" cases above.
     */
    public function test_a_missing_product_or_amount_is_rejected_with_an_inline_error_and_creates_nothing(): void
    {
        $owner = User::factory()->create();
        $customer = Customer::factory()->for($owner)->create();

        $this->actingAs($owner)->post(route('crm.deals.store'), [
            'customer_id' => $customer->id,
            'amount' => '100',
        ])->assertSessionHasErrors('title');

        $this->actingAs($owner)->post(route('crm.deals.store'), [
            'customer_id' => $customer->id,
            'title' => 'Ковёр',
        ])->assertSessionHasErrors('amount');

        $this->assertSame(0, Deal::count());
    }

    /** "Amount must be a positive number." Zero and negative are both rejected. */
    public function test_a_zero_or_negative_amount_is_rejected(): void
    {
        $owner = User::factory()->create();
        $customer = Customer::factory()->for($owner)->create();

        foreach (['0', '-50'] as $amount) {
            $this->actingAs($owner)->post(route('crm.deals.store'), [
                'customer_id' => $customer->id,
                'title' => 'Ковёр',
                'amount' => $amount,
            ])->assertSessionHasErrors('amount');
        }

        $this->assertSame(0, Deal::count());
    }

    /** The core feature: a customer and a deal for them, opened in one request. */
    public function test_creating_a_deal_with_a_brand_new_customer(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)->post(route('crm.deals.store'), [
            'customer_id' => '__new__',
            'new_customer_name' => 'Азиз Каримов',
            'new_customer_phone' => '+992 90 011 22 33',
            'title' => 'Ковёр 3х4',
            'amount' => '2500',
        ])->assertRedirect();

        $customer = Customer::sole();

        $this->assertSame('Азиз Каримов', $customer->name);
        $this->assertSame('+992 90 011 22 33', $customer->phone);
        $this->assertSame('lead', $customer->status);
        $this->assertSame('manual', $customer->source);
        $this->assertSame($owner->id, $customer->user_id);

        $deal = Deal::sole();
        $this->assertSame($customer->id, $deal->customer_id);
        $this->assertSame('Ковёр 3х4', $deal->title);
        $this->assertSame('new_lead', $deal->stage);
    }

    /** A new customer needs no phone at all — the same as the customer profile form. */
    public function test_a_new_customer_can_be_created_without_a_phone_number(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)->post(route('crm.deals.store'), [
            'customer_id' => '__new__',
            'new_customer_name' => 'Без номера',
            'title' => 'Ковёр',
            'amount' => '100',
        ])->assertRedirect();

        $customer = Customer::sole();
        $this->assertNull($customer->phone);
        $this->assertTrue($customer->isChatOnly());
    }

    /**
     * The duplicate case. The same phone number, typed again under "+ Новый
     * клиент", must never open a second profile — the existing one is used
     * instead, silently and safely, no matter what the client-side check did.
     */
    public function test_a_phone_number_that_already_matches_a_customer_is_not_duplicated(): void
    {
        $owner = User::factory()->create();
        $existing = Customer::factory()->for($owner)->withPhone('+992900112233')->create(['name' => 'Азиз']);

        $response = $this->actingAs($owner)->post(route('crm.deals.store'), [
            'customer_id' => '__new__',
            'new_customer_name' => 'Азиз Каримов', // typed fresh, deliberately not matching the stored name exactly
            'new_customer_phone' => '900112233', // same number, written without the country code
            'title' => 'Ковёр',
            'amount' => '100',
        ]);

        $response->assertRedirect();

        $this->assertSame(1, Customer::count());

        $deal = Deal::sole();
        $this->assertSame($existing->id, $deal->customer_id);
    }

    /** The lookup endpoint the modal's live check calls while the phone is typed. */
    public function test_the_phone_lookup_endpoint_finds_an_existing_customer_by_either_spelling(): void
    {
        $owner = User::factory()->create();
        $existing = Customer::factory()->for($owner)->withPhone('+992900112233')->create(['name' => 'Азиз']);

        $this->actingAs($owner)
            ->getJson(route('crm.customers.lookup', ['phone' => '900112233']))
            ->assertOk()
            ->assertJson(['customer' => ['id' => $existing->id, 'name' => 'Азиз']]);

        $this->actingAs($owner)
            ->getJson(route('crm.customers.lookup', ['phone' => '+992937000000']))
            ->assertOk()
            ->assertJson(['customer' => null]);
    }

    public function test_the_phone_lookup_never_reaches_another_owners_customer(): void
    {
        Customer::factory()->withPhone('+992900112233')->create();

        $this->actingAs(User::factory()->create())
            ->getJson(route('crm.customers.lookup', ['phone' => '900112233']))
            ->assertOk()
            ->assertJson(['customer' => null]);
    }

    public function test_the_deal_customer_search_finds_a_customer_beyond_the_old_dropdown_limit(): void
    {
        $owner = User::factory()->create();

        for ($i = 0; $i < 205; $i++) {
            Customer::factory()->for($owner)->create(['name' => "Покупатель {$i}"]);
        }
        $oldest = Customer::factory()->for($owner)->create(['name' => 'Покупатель за пределами первой страницы']);

        $this->actingAs($owner)->getJson(route('crm.customers.search', ['q' => 'за пределами']))
            ->assertOk()
            ->assertJsonCount(1, 'customers')
            ->assertJsonPath('customers.0.id', $oldest->id);
    }

    /** Logging a sale that happened before today: an explicit deal date. */
    public function test_a_deal_can_be_backdated_with_an_explicit_deal_date(): void
    {
        $owner = User::factory()->create();
        $customer = Customer::factory()->for($owner)->create();
        $date = now()->subDays(9)->startOfDay();

        $this->actingAs($owner)->post(route('crm.deals.store'), [
            'customer_id' => $customer->id,
            'title' => 'Ковёр',
            'amount' => '100',
            'deal_date' => $date->format('Y-m-d'),
        ])->assertRedirect();

        $deal = Deal::sole();
        $createdAt = $deal->created_at->copy();
        $this->assertTrue($deal->deal_date->isSameDay($date));
        $this->assertNotSame($deal->created_at->toDateString(), $date->toDateString());
        $this->assertTrue($deal->fresh()->created_at->equalTo($createdAt));
    }

    /** Left blank, a deal is still dated by when it was actually entered. */
    public function test_a_deal_with_no_date_falls_back_to_created_at(): void
    {
        $owner = User::factory()->create();
        $customer = Customer::factory()->for($owner)->create();

        $this->actingAs($owner)->post(route('crm.deals.store'), [
            'customer_id' => $customer->id,
            'title' => 'Ковёр',
            'amount' => '100',
        ])->assertRedirect();

        $deal = Deal::sole();
        $this->assertTrue($deal->created_at->isToday());
        $this->assertTrue($deal->deal_date->isToday());
    }

    /** Title and amount stay free text/free number — nothing constrains them to a catalog. */
    public function test_title_and_amount_accept_arbitrary_free_text_and_numbers(): void
    {
        $owner = User::factory()->create();
        $customer = Customer::factory()->for($owner)->create();

        $this->actingAs($owner)->post(route('crm.deals.store'), [
            'customer_id' => $customer->id,
            'title' => 'Что угодно, никакого каталога — 3 мешка цемента и доставка',
            'amount' => '13999.90',
        ])->assertRedirect();

        $deal = Deal::sole();
        $this->assertSame('Что угодно, никакого каталога — 3 мешка цемента и доставка', $deal->title);
        $this->assertSame('13999.90', $deal->amount);
    }
}
