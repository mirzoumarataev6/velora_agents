<?php

namespace Tests\Feature\Dataset;

use App\Models\Agent;
use App\Models\Dataset;
use App\Models\DatasetProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The owner's catalog browser in the Каталог tab. Separate concern from
 * search_products: this pages through everything that was imported, while the
 * agent's own tool stays capped (see ProductSearchTest).
 */
class CatalogPaginationTest extends TestCase
{
    use RefreshDatabase;

    protected function catalogOf(Agent $agent, int $products): Dataset
    {
        $dataset = Dataset::factory()->for($agent)->create(['row_count' => $products]);

        for ($i = 1; $i <= $products; $i++) {
            DatasetProduct::factory()->create([
                'agent_id' => $agent->id,
                'dataset_id' => $dataset->id,
                'name' => "Товар {$i}",
                'position' => $i - 1,
            ]);
        }

        return $dataset;
    }

    public function test_the_agent_page_seeds_the_first_page_and_the_total(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();
        $this->catalogOf($agent, 25);

        $this->actingAs($user)->get(route('agents.show', $agent))->assertOk();

        $state = $this->actingAs($user)->getJson(route('agents.catalog.show', $agent));

        $state->assertOk()
            ->assertJsonCount(10, 'preview')
            ->assertJsonPath('preview.0.name', 'Товар 1')
            ->assertJsonPath('pagination.page', 1)
            ->assertJsonPath('pagination.last_page', 3)
            ->assertJsonPath('pagination.total', 25)
            ->assertJsonPath('pagination.from', 1)
            ->assertJsonPath('pagination.to', 10);
    }

    public function test_paging_walks_the_whole_catalog_in_file_order(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();
        $this->catalogOf($agent, 25);

        $second = $this->actingAs($user)->getJson(route('agents.catalog.products', ['agent' => $agent, 'page' => 2]));
        $second->assertOk()
            ->assertJsonCount(10, 'preview')
            ->assertJsonPath('preview.0.name', 'Товар 11')
            ->assertJsonPath('pagination.page', 2)
            ->assertJsonPath('pagination.from', 11)
            ->assertJsonPath('pagination.to', 20);

        // The last page is short, and its range reflects that rather than
        // claiming a full page of rows.
        $third = $this->actingAs($user)->getJson(route('agents.catalog.products', ['agent' => $agent, 'page' => 3]));
        $third->assertOk()
            ->assertJsonCount(5, 'preview')
            ->assertJsonPath('preview.0.name', 'Товар 21')
            ->assertJsonPath('pagination.from', 21)
            ->assertJsonPath('pagination.to', 25);
    }

    /**
     * A stale "next" click after a re-upload that shortened the catalog must
     * land on the last real page, not on an empty table that reads as the
     * import having lost the data.
     */
    public function test_an_out_of_range_page_is_clamped_rather_than_returning_nothing(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();
        $this->catalogOf($agent, 25);

        $this->actingAs($user)->getJson(route('agents.catalog.products', ['agent' => $agent, 'page' => 99]))
            ->assertOk()
            ->assertJsonPath('pagination.page', 3)
            ->assertJsonCount(5, 'preview');

        foreach ([0, -3, 'abc'] as $page) {
            $this->actingAs($user)->getJson(route('agents.catalog.products', ['agent' => $agent, 'page' => $page]))
                ->assertOk()
                ->assertJsonPath('pagination.page', 1);
        }
    }

    public function test_a_catalog_that_fits_on_one_page_reports_a_single_page(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();
        $this->catalogOf($agent, 4);

        $this->actingAs($user)->getJson(route('agents.catalog.products', $agent))
            ->assertOk()
            ->assertJsonPath('pagination.last_page', 1)
            ->assertJsonPath('pagination.total', 4);
    }

    public function test_an_agent_with_no_catalog_reports_an_empty_pager(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();

        $this->actingAs($user)->getJson(route('agents.catalog.products', $agent))
            ->assertOk()
            ->assertJsonPath('preview', [])
            ->assertJsonPath('pagination.total', 0)
            ->assertJsonPath('pagination.last_page', 1);
    }

    public function test_paging_never_reaches_another_tenants_products(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $agent = Agent::factory()->for($owner)->create();
        $this->catalogOf($agent, 25);

        // Another tenant's much larger catalog must not leak in through a page
        // number that only exists in theirs.
        $theirAgent = Agent::factory()->for($stranger)->create();
        $this->catalogOf($theirAgent, 60);

        $mine = $this->actingAs($owner)->getJson(route('agents.catalog.products', ['agent' => $agent, 'page' => 3]));
        $mine->assertOk()->assertJsonPath('pagination.total', 25);

        $this->actingAs($stranger)
            ->getJson(route('agents.catalog.products', ['agent' => $agent, 'page' => 2]))
            ->assertForbidden();
    }
}
