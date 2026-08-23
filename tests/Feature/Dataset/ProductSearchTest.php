<?php

namespace Tests\Feature\Dataset;

use App\Models\Agent;
use App\Models\Dataset;
use App\Models\DatasetProduct;
use App\Models\User;
use App\Services\Dataset\ProductSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductSearchTest extends TestCase
{
    use RefreshDatabase;

    protected ProductSearchService $search;

    protected function setUp(): void
    {
        parent::setUp();

        $this->search = app(ProductSearchService::class);
    }

    /**
     * @param  array<int, array<string, mixed>>  $products
     */
    protected function catalog(Agent $agent, array $products): Dataset
    {
        $dataset = Dataset::factory()->for($agent)->create(['row_count' => count($products)]);

        foreach ($products as $position => $attributes) {
            DatasetProduct::factory()->create($attributes + [
                'agent_id' => $agent->id,
                'dataset_id' => $dataset->id,
                'position' => $position,
            ]);
        }

        return $dataset;
    }

    protected function agentWithCatalog(): Agent
    {
        $agent = Agent::factory()->for(User::factory())->create();

        $this->catalog($agent, [
            ['name' => 'Куртка зимняя', 'price' => 4500, 'stock' => 3, 'category' => 'Верхняя одежда', 'description' => 'Тёплая пуховая куртка'],
            ['name' => 'Куртка лёгкая', 'price' => 2200, 'stock' => 0, 'category' => 'Верхняя одежда', 'description' => 'Ветровка на весну'],
            ['name' => 'Шапка вязаная', 'price' => 450, 'stock' => 12, 'category' => 'Аксессуары', 'description' => 'Шерсть'],
            ['name' => 'Перчатки', 'price' => null, 'stock' => 7, 'category' => 'Аксессуары', 'description' => 'Цена по запросу'],
            ['name' => 'Ботинки', 'price' => 8900, 'stock' => 2, 'category' => 'Обувь', 'description' => 'Зимние'],
        ]);

        return $agent;
    }

    /**
     * @param  array<int, array<string, mixed>>  $products
     * @return string[]
     */
    protected function names(array $products): array
    {
        return array_column($products, 'name');
    }

    public function test_the_tool_is_only_offered_when_the_catalog_has_products(): void
    {
        $empty = Agent::factory()->for(User::factory())->create();
        $this->assertFalse($this->search->enabledFor($empty));

        // A dataset row with no products is still an empty catalog.
        Dataset::factory()->for($empty)->create();
        $this->assertFalse($this->search->enabledFor($empty->refresh()));

        $this->assertTrue($this->search->enabledFor($this->agentWithCatalog()));
    }

    public function test_a_text_query_matches_name_and_description(): void
    {
        $agent = $this->agentWithCatalog();

        $byName = $this->search->search($agent, ['query' => 'куртка']);
        $this->assertSame(['Куртка зимняя', 'Куртка лёгкая'], $this->names($byName['products']));

        $byDescription = $this->search->search($agent, ['query' => 'ветровка']);
        $this->assertSame(['Куртка лёгкая'], $this->names($byDescription['products']));
    }

    public function test_every_term_in_a_query_must_match(): void
    {
        $agent = $this->agentWithCatalog();

        $result = $this->search->search($agent, ['query' => 'куртка пуховая']);

        $this->assertSame(['Куртка зимняя'], $this->names($result['products']));
    }

    public function test_price_filters_narrow_the_results(): void
    {
        $agent = $this->agentWithCatalog();

        $cheap = $this->search->search($agent, ['max_price' => 2500]);
        $this->assertSame(['Куртка лёгкая', 'Шапка вязаная'], $this->names($cheap['products']));

        $band = $this->search->search($agent, ['min_price' => 2000, 'max_price' => 5000]);
        $this->assertSame(['Куртка зимняя', 'Куртка лёгкая'], $this->names($band['products']));
    }

    /**
     * A model that fills the bounds the wrong way round would otherwise get a
     * guaranteed-empty result and tell the customer nothing fits their budget.
     */
    public function test_reversed_price_bounds_are_swapped_rather_than_returning_nothing(): void
    {
        $agent = $this->agentWithCatalog();

        $result = $this->search->search($agent, ['min_price' => 5000, 'max_price' => 2000]);

        // Same results as the 2000–5000 band the model meant to ask for.
        $this->assertSame(['Куртка зимняя', 'Куртка лёгкая'], $this->names($result['products']));
    }

    public function test_in_stock_only_excludes_zero_stock(): void
    {
        $agent = $this->agentWithCatalog();

        $result = $this->search->search($agent, ['query' => 'куртка', 'in_stock_only' => true]);

        $this->assertSame(['Куртка зимняя'], $this->names($result['products']));
    }

    public function test_category_filter_matches_loosely(): void
    {
        $agent = $this->agentWithCatalog();

        $result = $this->search->search($agent, ['category' => 'аксессуар', 'limit' => 20]);

        $this->assertSame(['Шапка вязаная', 'Перчатки'], $this->names($result['products']));
    }

    public function test_sorting_by_price_puts_products_without_a_price_last(): void
    {
        $agent = $this->agentWithCatalog();

        $ascending = $this->search->search($agent, ['sort' => 'price_asc', 'limit' => 20]);
        $this->assertSame(
            ['Шапка вязаная', 'Куртка лёгкая', 'Куртка зимняя', 'Ботинки', 'Перчатки'],
            $this->names($ascending['products']),
        );

        $descending = $this->search->search($agent, ['sort' => 'price_desc', 'limit' => 20]);
        $this->assertSame('Ботинки', $descending['products'][0]['name']);
        $this->assertSame('Перчатки', end($descending['products'])['name']);
    }

    // --- the hard limit ----------------------------------------------------

    public function test_the_limit_is_capped_however_many_rows_the_model_asks_for(): void
    {
        $agent = Agent::factory()->for(User::factory())->create();
        $this->catalog($agent, array_map(
            fn (int $i) => ['name' => "Товар {$i}", 'price' => 100 + $i, 'stock' => 1],
            range(1, 60),
        ));

        foreach ([1000, 100, ProductSearchService::MAX_LIMIT + 1] as $requested) {
            $result = $this->search->search($agent, ['limit' => $requested]);

            $this->assertCount(ProductSearchService::MAX_LIMIT, $result['products']);
            $this->assertSame(ProductSearchService::MAX_LIMIT, $result['limit']);
        }
    }

    public function test_a_missing_or_nonsense_limit_falls_back_to_the_default(): void
    {
        $agent = Agent::factory()->for(User::factory())->create();
        $this->catalog($agent, array_map(
            fn (int $i) => ['name' => "Товар {$i}", 'price' => 100, 'stock' => 1],
            range(1, 30),
        ));

        foreach ([[], ['limit' => 0], ['limit' => -5], ['limit' => 'many']] as $args) {
            $result = $this->search->search($agent, $args);

            $this->assertCount(ProductSearchService::DEFAULT_LIMIT, $result['products']);
        }
    }

    public function test_a_wildcard_in_the_query_does_not_match_the_whole_catalog(): void
    {
        $agent = $this->agentWithCatalog();

        $result = $this->search->search($agent, ['query' => '%', 'limit' => 20]);

        // '%' is stripped, leaving an empty term that matches nothing rather
        // than every row in the table.
        $this->assertSame(0, $result['count']);
    }

    // --- multi-tenant isolation -------------------------------------------

    public function test_a_search_never_returns_another_tenants_products(): void
    {
        $mine = $this->agentWithCatalog();

        $theirs = Agent::factory()->for(User::factory())->create();
        $this->catalog($theirs, [
            ['name' => 'Куртка чужая', 'price' => 100, 'stock' => 99, 'category' => 'Верхняя одежда'],
        ]);

        foreach ([
            ['query' => 'куртка', 'limit' => 20],
            ['limit' => 20],
            ['max_price' => 1000000, 'limit' => 20],
            ['category' => 'Верхняя одежда', 'limit' => 20],
            ['in_stock_only' => true, 'limit' => 20],
        ] as $args) {
            $names = $this->names($this->search->search($mine, $args)['products']);

            $this->assertNotContains('Куртка чужая', $names);
        }

        $this->assertSame(['Куртка чужая'], $this->names($this->search->search($theirs, ['limit' => 20])['products']));
    }

    /**
     * There is no argument that names an agent, so there is nothing for a prompt
     * injection to point at another tenant. Arguments that look like one are
     * simply ignored.
     */
    public function test_agent_scoping_cannot_be_overridden_by_tool_arguments(): void
    {
        $mine = Agent::factory()->for(User::factory())->create();
        $this->catalog($mine, [['name' => 'Мой товар', 'price' => 100, 'stock' => 1]]);

        $theirs = Agent::factory()->for(User::factory())->create();
        $this->catalog($theirs, [['name' => 'Чужой товар', 'price' => 100, 'stock' => 1]]);

        $result = $this->search->search($mine, [
            'agent_id' => $theirs->id,
            'tenant' => $theirs->id,
            'limit' => 20,
        ]);

        $this->assertSame(['Мой товар'], $this->names($result['products']));
    }

    // --- graceful empties --------------------------------------------------

    public function test_an_empty_catalog_returns_no_results_rather_than_erroring(): void
    {
        $agent = Agent::factory()->for(User::factory())->create();

        $result = $this->search->search($agent, ['query' => 'куртка']);

        $this->assertSame(0, $result['count']);
        $this->assertSame([], $result['products']);
        $this->assertArrayHasKey('note', $result);
    }

    public function test_no_match_returns_guidance_the_agent_can_act_on(): void
    {
        $agent = $this->agentWithCatalog();

        $result = $this->search->search($agent, ['query' => 'холодильник']);

        $this->assertSame(0, $result['count']);
        // The note must steer away from "we sell nothing", which is the failure
        // an empty result invites.
        $this->assertStringContainsString('does NOT mean', $result['note']);
    }

    public function test_results_are_presented_without_internal_identifiers(): void
    {
        $agent = $this->agentWithCatalog();

        $product = $this->search->search($agent, ['query' => 'шапка'])['products'][0];

        $this->assertSame('Шапка вязаная', $product['name']);
        // Whole-number prices read back as "450", not "450.00".
        $this->assertSame('450', $product['price']);
        $this->assertTrue($product['in_stock']);
        $this->assertArrayNotHasKey('id', $product);
        $this->assertArrayNotHasKey('agent_id', $product);
        $this->assertArrayNotHasKey('dataset_id', $product);
    }
}
