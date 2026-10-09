<?php

namespace Tests\Feature\Crm;

use App\Models\CrmWorkspace;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_a_workspace_and_it_becomes_the_active_folder(): void
    {
        $owner = User::factory()->create();

        $response = $this->actingAs($owner)->post(route('crm.workspaces.store'), [
            'name' => 'Khujand store',
            'color' => 'violet',
            'origin' => 'in_person',
        ]);

        $workspace = CrmWorkspace::sole();
        $response->assertRedirect(route('crm.board', ['origin' => 'in_person', 'workspace' => $workspace->id]));
        $this->assertSame($owner->id, $workspace->user_id);
        $this->assertSame('violet', $workspace->color);
    }

    public function test_workspace_names_are_unique_per_owner_and_color_is_validated(): void
    {
        $owner = User::factory()->create();
        CrmWorkspace::forceCreate(['user_id' => $owner->id, 'name' => 'Retail', 'color' => 'blue']);

        $this->actingAs($owner)->post(route('crm.workspaces.store'), [
            'name' => 'Retail', 'color' => 'violet',
        ])->assertSessionHasErrors('name');

        $this->actingAs($owner)->post(route('crm.workspaces.store'), [
            'name' => 'Another', 'color' => 'ultraviolet',
        ])->assertSessionHasErrors('color');
    }

    public function test_workspace_can_be_renamed_and_recolored_by_its_owner(): void
    {
        $owner = User::factory()->create();
        $workspace = CrmWorkspace::forceCreate([
            'user_id' => $owner->id, 'name' => 'Retail', 'color' => 'blue',
        ]);

        $this->actingAs($owner)->patch(route('crm.workspaces.update', $workspace), [
            'name' => 'Khujand shop',
            'color' => 'rose',
            'origin' => 'social',
        ])->assertRedirect(route('crm.board', ['origin' => 'social', 'workspace' => $workspace->id]));

        $this->assertSame('Khujand shop', $workspace->fresh()->name);
        $this->assertSame('rose', $workspace->fresh()->color);
    }

    public function test_a_workspace_id_from_another_owner_cannot_be_used_for_a_deal(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $workspace = CrmWorkspace::forceCreate(['user_id' => $other->id, 'name' => 'Private folder', 'color' => 'blue']);
        $customer = Customer::factory()->for($owner)->create();

        $this->actingAs($owner)->post(route('crm.deals.store'), [
            'customer_id' => $customer->id,
            'title' => 'Ноутбук',
            'amount' => 1200,
            'sales_origin' => 'in_person',
            'crm_workspace_id' => $workspace->id,
        ])->assertSessionHasErrors('crm_workspace_id');

        $this->assertSame(0, Deal::count());
    }

    public function test_online_and_offline_rows_filter_inside_the_selected_workspace(): void
    {
        $owner = User::factory()->create();
        $customer = Customer::factory()->for($owner)->create();
        $workspace = CrmWorkspace::forceCreate(['user_id' => $owner->id, 'name' => 'Store', 'color' => 'green']);

        $online = Deal::factory()->for($owner)->for($customer)->create([
            'crm_workspace_id' => $workspace->id,
            'sales_origin' => 'social',
        ]);
        $offline = Deal::factory()->for($owner)->for($customer)->create([
            'crm_workspace_id' => $workspace->id,
            'sales_origin' => 'in_person',
        ]);
        Deal::factory()->for($owner)->for($customer)->create([
            'sales_origin' => 'in_person',
        ]);

        $this->actingAs($owner)->getJson(route('crm.deals.poll', [
            'origin' => 'in_person',
            'workspace' => $workspace->id,
        ]))->assertOk()
            ->assertJsonCount(1, 'deals')
            ->assertJsonPath('deals.0.id', $offline->id)
            ->assertJsonPath('summary.origin_counts.social', 1)
            ->assertJsonPath('summary.origin_counts.in_person', 1);

        $this->assertNotSame($online->id, $offline->id);
    }

    public function test_deleting_a_folder_keeps_its_deals_in_the_default_folder(): void
    {
        $owner = User::factory()->create();
        $default = CrmWorkspace::defaultFor($owner);
        $workspace = CrmWorkspace::forceCreate(['user_id' => $owner->id, 'name' => 'Temporary', 'color' => 'rose']);
        $deal = Deal::factory()->for($owner)->for(Customer::factory()->for($owner))->create([
            'crm_workspace_id' => $workspace->id,
        ]);

        $this->actingAs($owner)->delete(route('crm.workspaces.destroy', $workspace))
            ->assertRedirect(route('crm.board'));

        $this->assertDatabaseMissing('crm_workspaces', ['id' => $workspace->id]);
        $this->assertSame($default->id, $deal->fresh()->crm_workspace_id);
    }

    public function test_owner_cannot_open_or_mutate_another_owners_workspace(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $workspace = CrmWorkspace::forceCreate(['user_id' => $other->id, 'name' => 'Not yours', 'color' => 'blue']);

        $this->actingAs($owner)->patch(route('crm.workspaces.update', $workspace), [
            'name' => 'Changed', 'color' => 'rose',
        ])->assertForbidden();

        $this->actingAs($owner)->delete(route('crm.workspaces.destroy', $workspace))->assertForbidden();
        $this->assertSame('Not yours', $workspace->fresh()->name);
    }

    public function test_new_workspace_and_sales_labels_exist_in_all_supported_locales(): void
    {
        foreach (['ru', 'tg', 'en'] as $locale) {
            app()->setLocale($locale);

            foreach ([
                'crm.workspaces.heading',
                'crm.workspaces.create_title',
                'crm.workspaces.default_name',
                'crm.workspaces.delete_confirm',
                'crm.workspaces.colors.violet',
                'crm.customers.online_purchases',
                'crm.customers.offline_purchases',
                'crm.customers.purchase_empty',
            ] as $key) {
                $this->assertNotSame($key, __($key), "{$key} is missing in {$locale}");
            }
        }
    }
}
