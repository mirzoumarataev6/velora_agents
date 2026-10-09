<?php

namespace Tests\Feature\Crm;

use App\Models\Customer;
use App\Models\Deal;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CustomerDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_directory_shows_one_row_per_customer_with_paid_purchases_and_conversations_counted(): void
    {
        $owner = User::factory()->create();
        $customer = Customer::factory()->for($owner)->create(['name' => 'Фаррух']);
        Deal::factory()->count(3)->for($owner)->for($customer)->create([
            'payment_status' => 'paid', 'stage' => 'payment', 'amount' => 120,
        ]);
        Deal::factory()->for($owner)->for($customer)->create([
            'payment_status' => 'unpaid', 'stage' => 'haggling', 'amount' => 80,
        ]);

        $this->actingAs($owner)->get(route('crm.customers.index'))
            ->assertOk()
            ->assertSee('Фаррух')
            ->assertSee('360')
            ->assertSee(__('crm.customers.directory.heading'));

        $groupedQueries = [];
        DB::listen(function (QueryExecuted $query) use (&$groupedQueries): void {
            if (str_contains($query->sql, 'COUNT(*) >= 2')) {
                $groupedQueries[] = strtolower($query->sql);
            }
        });

        $this->actingAs($owner)->get(route('crm.customers.index', ['segment' => 'repeat']))
            ->assertOk()->assertSee('Фаррух');

        $this->assertNotEmpty($groupedQueries);
        foreach ($groupedQueries as $sql) {
            $this->assertStringNotContainsString('select *', $sql);
        }

        $this->actingAs($owner)->get(route('crm.customers.index', ['segment' => 'buyers']))
            ->assertOk()->assertSee('Фаррух');
    }

    public function test_directory_segments_and_workspace_filter_never_include_another_owner(): void
    {
        $owner = User::factory()->create();
        $otherOwner = User::factory()->create();
        $folder = $owner->crmWorkspaces()->create(['name' => 'Худжанд', 'color' => 'blue']);
        $mine = Customer::factory()->for($owner)->create(['name' => 'Мой клиент', 'crm_workspace_id' => $folder->id]);
        $theirs = Customer::factory()->for($otherOwner)->create(['name' => 'Чужой клиент']);

        $this->actingAs($owner)->get(route('crm.customers.index', ['workspace' => $folder->id]))
            ->assertOk()->assertSee('Мой клиент')->assertDontSee('Чужой клиент');

        $this->actingAs($owner)->get(route('crm.customers.index', ['segment' => 'no_purchases']))
            ->assertOk()->assertSee('Мой клиент')->assertDontSee('Чужой клиент');
    }

    public function test_live_search_returns_only_the_updated_results_fragment(): void
    {
        $owner = User::factory()->create();
        Customer::factory()->for($owner)->create(['name' => 'Бобур']);
        Customer::factory()->for($owner)->create(['name' => 'Фаррух']);

        $this->actingAs($owner)->get(route('crm.customers.index', ['q' => 'Боб']), [
            'X-Requested-With' => 'XMLHttpRequest',
        ])->assertOk()
            ->assertSee('Бобур')
            ->assertDontSee('Фаррух')
            ->assertDontSee(__('crm.customers.directory.heading'));
    }

    public function test_customer_segments_are_filter_cards_and_filters_apply_without_submit_controls(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)->get(route('crm.customers.index'))
            ->assertOk()
            ->assertSee('setSegment(', false)
            ->assertSee('aria-pressed=', false)
            ->assertSee('@change="search($refs.search.value)"', false)
            ->assertDontSee(__('crm.customers.directory.apply'))
            ->assertDontSee(__('crm.customers.directory.reset'));
    }

    public function test_changing_a_customer_folder_moves_its_deals_with_it_and_rejects_foreign_folder(): void
    {
        $owner = User::factory()->create();
        $customer = Customer::factory()->for($owner)->create();
        $deal = Deal::factory()->for($owner)->for($customer)->create();
        $folder = $owner->crmWorkspaces()->create(['name' => 'Повторные', 'color' => 'violet']);
        $foreignOwner = User::factory()->create();
        $foreignFolder = $foreignOwner->crmWorkspaces()->create(['name' => 'Private', 'color' => 'rose']);

        $this->actingAs($owner)->patch(route('crm.customers.update', $customer), [
            'crm_workspace_id' => $folder->id,
        ])->assertRedirect();

        $this->assertSame($folder->id, $customer->fresh()->crm_workspace_id);
        $this->assertSame($folder->id, $deal->fresh()->crm_workspace_id);

        $this->actingAs($owner)->patch(route('crm.customers.update', $customer), [
            'crm_workspace_id' => $foreignFolder->id,
        ])->assertSessionHasErrors('crm_workspace_id');
    }
}
