<?php

namespace Tests\Feature\Crm;

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrmNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_sidebar_offers_crm_as_its_own_section(): void
    {
        $response = $this->actingAs(User::factory()->create())->get(route('crm.board'));

        $response->assertOk();
        $response->assertSee('href="'.route('crm.board').'"', false);
        $response->assertSee(__('shell.nav.crm'));
        $response->assertSee('aria-current="page"', false);
    }

    /** CRM is where the owner works; Аналитика stays a report. Both exist. */
    public function test_crm_is_distinct_from_the_analytics_dashboard(): void
    {
        $response = $this->actingAs(User::factory()->create())->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee(route('crm.board'));
        $response->assertSee(__('shell.nav.dashboard'));
        $response->assertSee(__('shell.nav.crm'));
        $response->assertSee('aria-current="page"', false);
    }

    /** Заявки is untouched and still reachable — it was not replaced. */
    public function test_the_existing_leads_section_is_still_there(): void
    {
        $response = $this->actingAs(User::factory()->create())->get(route('leads.index'));

        $response->assertOk();
        $response->assertSee(__('shell.nav.leads'));
    }

    /** Project work has its own section; CRM keeps customer and deal context. */
    public function test_every_crm_sub_page_is_reachable_and_marks_itself_active(): void
    {
        $user = User::factory()->create();

        foreach (['crm.board', 'crm.customers.index'] as $route) {
            $response = $this->actingAs($user)->get(route($route));

            $response->assertOk();
            foreach (['board', 'customers'] as $tab) {
                $response->assertSee(__('crm.nav.'.$tab));
            }
            $response->assertDontSee('href="'.route('crm.tasks.index').'"', false);

            $activeUrl = route($route);
            $response->assertSee($activeUrl);
            $response->assertSee('aria-current="page"', false);
            $response->assertSee('tab-active', false);
        }
    }

    public function test_the_customer_directory_is_a_first_class_crm_destination(): void
    {
        $response = $this->actingAs(User::factory()->create())->get(route('crm.customers.index'));

        $response->assertOk();
        $response->assertSee(__('crm.nav.customers'));
        $response->assertSee(__('crm.customers.directory.heading'));
    }

    /**
     * CRM navigation now goes through Turbo, the same as every other route —
     * there is no more server-side "partial" response mode (the retired
     * X-CRM-Partial header / layouts.crm-partial). Every CRM page is always
     * the full page, chrome included; Turbo decides client-side what to swap.
     */
    public function test_crm_pages_always_render_the_full_chrome(): void
    {
        $this->actingAs(User::factory()->create())->get(route('crm.tasks.index'))
            ->assertOk()
            ->assertSee('<main data-app-main', false)
            ->assertSee(__('crm.tasks.heading'))
            ->assertSee('data-sidebar', false);
    }

    public function test_the_profile_links_a_customers_deals_and_tasks(): void
    {
        $owner = User::factory()->create();
        $customer = Customer::factory()->for($owner)->create();
        Deal::factory()->for($owner)->for($customer)->stage('payment')->create(['title' => 'Ковёр 3х4']);
        Task::factory()->for($owner)->create(['customer_id' => $customer->id, 'title' => 'Перезвонить завтра']);

        $response = $this->actingAs($owner)->get(route('crm.customers.show', $customer));

        $response->assertOk();
        $response->assertSee('Ковёр 3х4');
        $response->assertSee('Перезвонить завтра');
        $response->assertSee(__('crm.stages.payment'));
        $response->assertSee('lg:flex-1', false);
        $response->assertSee('lg:min-h-0 lg:flex-1', false);
    }

    /**
     * The core IA change: opening a deal shows the customer behind it —
     * phone, status, tags — without navigating anywhere else.
     */
    public function test_the_deal_panel_carries_the_customers_phone_status_and_tags(): void
    {
        $owner = User::factory()->create();
        $customer = Customer::factory()->for($owner)->withPhone('+992900112233')
            ->status('customer')->create(['tags' => ['опт']]);
        Deal::factory()->for($owner)->for($customer)->create();

        $response = $this->actingAs($owner)->getJson(route('crm.deals.poll'));

        $response->assertJsonPath('deals.0.customer_phone', '+992900112233');
        $response->assertJsonPath('deals.0.customer_status', 'customer');
        $response->assertJsonPath('deals.0.customer_tags', ['опт']);
    }

    /**
     * A customer with no deal at all does not just vanish from the CRM —
     * they surface in the "Без сделки" bucket instead of a separate screen.
     */
    public function test_a_customer_with_no_deal_appears_in_the_leads_bucket(): void
    {
        $owner = User::factory()->create();
        $withDeal = Customer::factory()->for($owner)->create(['name' => 'Со сделкой']);
        Deal::factory()->for($owner)->for($withDeal)->create();

        $withoutDeal = Customer::factory()->for($owner)->create(['name' => 'Без сделки']);

        $response = $this->actingAs($owner)->getJson(route('crm.deals.poll', ['stage' => 'leads']));

        $names = collect($response->json('leads'))->pluck('name');

        $this->assertTrue($names->contains('Без сделки'));
        $this->assertFalse($names->contains('Со сделкой'));
    }
}
