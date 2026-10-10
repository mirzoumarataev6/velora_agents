<?php

namespace Tests\Feature\Crm;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AddCustomerTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_owner_can_add_a_customer_by_hand(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)->post(route('crm.customers.store'), [
            'name' => 'Азиз', 'phone' => '+992 90 011 22 33', 'status' => 'active', 'note' => 'С рынка',
        ])->assertRedirect();

        $customer = Customer::ownedBy($owner)->sole();
        $this->assertSame('Азиз', $customer->name);
        $this->assertSame('900112233', $customer->phone_key);
        $this->assertSame('active', $customer->status);
        $this->assertSame('manual', $customer->source);
    }

    /**
     * The one rule that must always hold: a phone number never gets a
     * second profile — surfacing the existing one instead of a duplicate.
     */
    public function test_adding_a_customer_with_an_existing_number_surfaces_that_profile_instead(): void
    {
        $owner = User::factory()->create();
        $existing = Customer::factory()->for($owner)->create(['name' => 'Фарид', 'phone' => '+992900112233', 'phone_key' => '900112233']);

        $this->actingAs($owner)->post(route('crm.customers.store'), [
            'name' => 'Другое имя', 'phone' => '900112233',
        ])->assertRedirect(route('crm.customers.show', $existing));

        $this->assertSame(1, Customer::ownedBy($owner)->count());
    }
}
