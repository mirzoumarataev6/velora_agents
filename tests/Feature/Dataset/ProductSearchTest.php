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

    public function test_search_results_keep_the_owner_mapped_product_image_url(): void
    {
        $agent = Agent::factory()->for(User::factory())->create();
        $this->catalog($agent, [[
            'name' => 'Ноутбук X', 'image_url' => 'https://shop.example.test/x.jpg',
        ]]);

        $result = $this->search->search($agent, ['query' => 'Ноутбук X']);

        $this->assertSame('https://shop.example.test/x.jpg', $result['products'][0]['image_url']);
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
        $this->assertStringContainsString('does not mean the business sells nothing', $result['note']);
        // And it must be about THIS search, not a sentence the model could have
        // memorised — see the repetition test below.
        $this->assertStringContainsString('холодильник', $result['note']);
    }

    // --- the shop that reported this: a laptop catalog -----------------------

    /**
     * The catalog behind the bug report, kept close to the file that was
     * uploaded: a laptop shop whose columns are №, Модель, Категория, Процессор,
     * ОЗУ, Память, Цена (сомони). The product type is in the CATEGORY, the
     * attributes are in extras, and the customer asks in the plural.
     */
    protected function agentWithLaptops(): Agent
    {
        $agent = Agent::factory()->for(User::factory())->create();

        // description and sku are pinned to null rather than left to the factory:
        // a random sku like "QX-4812" would put stray digits into the search
        // text this fixture exists to make assertions about.
        $this->catalog($agent, [
            [
                'name' => 'Lenovo LOQ 15', 'price' => 14999, 'stock' => 2,
                'description' => null, 'sku' => null,
                'category' => 'Ноутбук (игровой)',
                'extra' => ['Процессор' => 'Core i5-12450H', 'ОЗУ' => '16 ГБ', 'Память' => '512 ГБ SSD'],
            ],
            [
                'name' => 'HP Victus 16', 'price' => 18500, 'stock' => 1,
                'description' => null, 'sku' => null,
                'category' => 'Ноутбук (игровой)',
                'extra' => ['Процессор' => 'Ryzen 7', 'ОЗУ' => '16 ГБ', 'Память' => '1 ТБ SSD'],
            ],
            [
                'name' => 'Acer Aspire 3', 'price' => 6200, 'stock' => 5,
                'description' => null, 'sku' => null,
                'category' => 'Ноутбук (офисный)',
                'extra' => ['Процессор' => 'Core i3', 'ОЗУ' => '8 ГБ', 'Память' => '256 ГБ SSD'],
            ],
            [
                'name' => 'Logitech M190', 'price' => 180, 'stock' => 40,
                'description' => null, 'sku' => null,
                'category' => 'Мышь',
                'extra' => ['Тип' => 'Беспроводная'],
            ],
        ]);

        return $agent;
    }

    /**
     * The reported failure, end to end: "игровые ноутбуки до 15000 сомони"
     * returned nothing while a 14999 gaming laptop sat in the catalog. Three
     * separate things had to be wrong for that, and this asserts all three at
     * once — the plural, the category wording, and the budget.
     */
    public function test_a_plural_query_for_a_category_within_a_budget_finds_the_product(): void
    {
        $agent = $this->agentWithLaptops();

        $result = $this->search->search($agent, [
            'query' => 'игровые ноутбуки',
            'max_price' => 15000,
        ]);

        $this->assertSame(['Lenovo LOQ 15'], $this->names($result['products']));
    }

    public function test_a_query_matches_words_that_only_appear_in_the_category(): void
    {
        $agent = $this->agentWithLaptops();

        // "игровой" is in no product name — only in the category column.
        $result = $this->search->search($agent, ['query' => 'игровой ноутбук', 'limit' => 20]);

        $this->assertSame(['Lenovo LOQ 15', 'HP Victus 16'], $this->names($result['products']));
    }

    public function test_a_query_matches_the_attribute_columns_the_shop_uploaded(): void
    {
        $agent = $this->agentWithLaptops();

        // Neither the header nor the value is a fixed field: they arrived as
        // extras because this shop's file happened to have them.
        $this->assertSame(
            ['Acer Aspire 3'],
            $this->names($this->search->search($agent, ['query' => 'ноутбук 8 ГБ'])['products']),
        );

        $this->assertSame(
            ['Lenovo LOQ 15'],
            $this->names($this->search->search($agent, ['query' => 'i5-12450H'])['products']),
        );
    }

    public function test_the_category_filter_matches_a_word_inside_the_shops_own_category_name(): void
    {
        $agent = $this->agentWithLaptops();

        // The model states the category the way the customer said it; the shop
        // wrote "Ноутбук (игровой)". Every word has to land, in any form.
        $result = $this->search->search($agent, ['category' => 'игровые ноутбуки', 'limit' => 20]);

        $this->assertSame(['Lenovo LOQ 15', 'HP Victus 16'], $this->names($result['products']));
    }

    public function test_the_category_filter_still_requires_every_word_to_match(): void
    {
        $agent = $this->agentWithLaptops();

        // "мышь" is a real category and "игровая" is a real word, but nothing is
        // both — a filter where one word matches must not open the whole shop.
        $result = $this->search->search($agent, ['category' => 'игровая мышь', 'limit' => 20]);

        $this->assertSame(0, $result['count']);
    }

    public function test_a_category_and_a_price_range_narrow_together(): void
    {
        $agent = $this->agentWithLaptops();

        $result = $this->search->search($agent, [
            'category' => 'ноутбук',
            'min_price' => 10000,
            'max_price' => 20000,
            'sort' => 'price_asc',
            'limit' => 20,
        ]);

        // The office laptop is in the category and out of the band; the mouse is
        // in neither.
        $this->assertSame(['Lenovo LOQ 15', 'HP Victus 16'], $this->names($result['products']));
    }

    public function test_the_price_bound_is_inclusive_of_the_bound_itself(): void
    {
        $agent = $this->agentWithLaptops();

        // 14999 under a 14999 ceiling: an exclusive comparison here is how a
        // catalog loses the one product that fits the customer's budget exactly.
        $this->assertSame(
            ['Lenovo LOQ 15'],
            $this->names($this->search->search($agent, ['max_price' => 14999, 'min_price' => 14999])['products']),
        );
    }

    // --- what an empty result tells the model (BUG 3) ------------------------

    public function test_an_empty_result_offers_the_products_that_were_one_filter_away(): void
    {
        $agent = $this->agentWithLaptops();

        // Gaming laptops exist; none is under 10000. The model must be handed
        // the near misses rather than only the news that there was no match.
        $result = $this->search->search($agent, [
            'category' => 'игровой',
            'max_price' => 10000,
        ]);

        $this->assertSame(0, $result['count']);
        $this->assertSame(['Lenovo LOQ 15', 'HP Victus 16'], $this->names($result['nearest']));
        $this->assertStringContainsString('EXCEPT the price', $result['nearest_note']);
        // And it must not be able to pass them off as being within budget.
        $this->assertStringContainsString('nothing fits the budget', $result['nearest_note']);
    }

    public function test_an_empty_result_widens_the_category_when_the_budget_was_not_the_problem(): void
    {
        $agent = $this->agentWithLaptops();

        $result = $this->search->search($agent, ['category' => 'планшет']);

        $this->assertSame(0, $result['count']);
        $this->assertNotSame([], $result['nearest']);
        $this->assertStringContainsString('more widely', $result['nearest_note']);
    }

    /**
     * The repetition bug: the model used to be handed one fixed sentence for
     * every miss and, unsurprisingly, sent one fixed sentence back every time.
     * Two different misses must not produce the same guidance.
     */
    public function test_two_different_empty_searches_do_not_produce_the_same_guidance(): void
    {
        $agent = $this->agentWithLaptops();

        $first = $this->search->search($agent, ['query' => 'холодильник']);
        $second = $this->search->search($agent, ['category' => 'ноутбук', 'max_price' => 500]);

        $this->assertNotSame($first['note'], $second['note']);
        $this->assertStringContainsString('холодильник', $first['note']);
        $this->assertStringContainsString('up to 500', $second['note']);
        // Whatever else it says, it has to forbid reusing the last reply.
        foreach ([$first, $second] as $result) {
            $this->assertStringContainsString('Do NOT repeat', $result['note']);
        }
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
