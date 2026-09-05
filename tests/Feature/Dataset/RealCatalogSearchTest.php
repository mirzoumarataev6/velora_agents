<?php

namespace Tests\Feature\Dataset;

use App\Models\Agent;
use App\Models\DatasetProduct;
use App\Services\Dataset\ColumnMapper;
use App\Services\Dataset\DatasetImporter;
use App\Services\Dataset\ProductSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A real shop's catalog, imported through the real import path, searched the way
 * the model searches it.
 *
 * This file exists because the previous round's fixtures were "similar to" the
 * failing catalog rather than identical to it, and the difference is exactly
 * where the bug lived: the real file has NO STOCK COLUMN. Every row's stock is
 * therefore NULL, `stock > 0` is false for NULL, and a single in_stock_only
 * argument — which the model reaches for whenever a customer asks "есть ли у
 * вас…", because that reads as asking what is available — excluded all ten rows.
 * The search layer was working perfectly; the catalog was invisible to it.
 *
 * So the fixture is the shop's own ten rows, verbatim, and the queries are the
 * two that failed.
 */
class RealCatalogSearchTest extends TestCase
{
    use RefreshDatabase;

    /** The file's own header row. Note what is absent: stock, sku, description. */
    protected const HEADERS = ['Модель', 'Цена', 'Категория', 'Процессор', 'ОЗУ', 'Память', 'Видеокарта'];

    /** @var array<int, array<int, string>> */
    protected const ROWS = [
        ['Macbook Neo 13', '8199.00', 'Ноутбук', 'Apple', '8GB', '256GB', '—'],
        ['Macbook Neo 13', '8900.00', 'Ноутбук', 'Apple', '8GB', '512GB', '—'],
        ['Macbook Air 15', '11999.00', 'Ноутбук', 'Apple M4', '16GB', '256GB', '—'],
        ['Macbook Air 13.6', '10499.00', 'Ноутбук', 'Apple M4', '16GB', '256GB', '—'],
        ['Macbook Pro 14', '17999.00', 'Ноутбук', 'Apple M5', '16GB', '512GB', '—'],
        ['Macbook Pro 14', '24999.00', 'Ноутбук (офисный)', 'Apple M5', '24GB', '1TB', '—'],
        ['15 15.6" FHD', '4699.00', 'Ноутбук', 'Intel Core i3-N305', '8GB', '256GB', '—'],
        ['15 15.6" FHD', '7999.00', 'Ноутбук', 'Intel Core Ultra 7 255U', '16GB', '512GB', '—'],
        ['OMEN 16', '14999.00', 'Ноутбук (игровой)', 'Intel Core Ultra 7 255H', '16GB', '1TB', '—'],
        ['27f LED', '2199.00', 'Монитор', '—', '—', '—', '—'],
    ];

    protected ProductSearchService $search;

    protected function setUp(): void
    {
        parent::setUp();

        $this->search = app(ProductSearchService::class);
    }

    /**
     * Imported the way the Каталог tab imports it: the suggested mapping, the
     * real importer, no hand-written rows.
     */
    protected function shop(): Agent
    {
        $agent = Agent::factory()->create();

        app(DatasetImporter::class)->import(
            $agent,
            'catalog.xlsx',
            self::HEADERS,
            self::ROWS,
            app(ColumnMapper::class)->suggest(self::HEADERS, self::ROWS),
        );

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

    public function test_the_file_is_mapped_without_inventing_a_stock_or_article_column(): void
    {
        $mapping = app(ColumnMapper::class)->suggest(self::HEADERS, self::ROWS);

        $this->assertSame(['name' => 0, 'price' => 1, 'category' => 2, 'extra' => [3, 4, 5, 6]], $mapping);
    }

    /**
     * Step one of the investigation, kept as a test: the category really is in
     * the stored search text. Everything downstream depends on it.
     */
    public function test_the_category_and_attributes_are_stored_in_the_search_text(): void
    {
        $this->shop();

        $omen = DatasetProduct::where('name', 'OMEN 16')->sole();
        $office = DatasetProduct::where('price', '24999.00')->sole();

        $this->assertSame(
            'omen 16 ноутбук (игровой) процессор intel core ultra 7 255h озу 16gb память 1tb',
            $omen->search_text,
        );
        $this->assertStringContainsString('игровой', $omen->search_text);
        $this->assertStringContainsString('офисный', $office->search_text);

        // The dash in the Видеокарта column is "no value", not a value.
        $this->assertArrayNotHasKey('Видеокарта', $omen->extra);
    }

    /**
     * The two reported queries, in every argument shape the model plausibly
     * produces for them — including the one that was actually failing.
     */
    public function test_the_two_reported_queries_find_their_products(): void
    {
        $agent = $this->shop();

        foreach ([
            'plain query' => ['query' => 'игровые ноуты'],
            'query with a stock filter' => ['query' => 'игровые ноуты', 'in_stock_only' => true],
            'category filter' => ['category' => 'игровой'],
            'category filter, plural' => ['category' => 'игровые ноутбуки'],
            'category and query' => ['category' => 'игровой', 'query' => 'ноутбук'],
            'everything at once' => ['query' => 'игровой ноутбук', 'category' => 'игровой', 'in_stock_only' => true, 'max_price' => 20000],
        ] as $shape => $args) {
            $result = $this->search->search($agent, $args);

            $this->assertSame(['OMEN 16'], $this->names($result['products']), "gaming laptops, {$shape}");
        }

        foreach ([
            'plain query' => ['query' => 'офисные'],
            'query with a stock filter' => ['query' => 'офисные ноутбуки', 'in_stock_only' => true],
            'category filter' => ['category' => 'офисный'],
            'category filter, plural' => ['category' => 'офисные ноутбуки'],
        ] as $shape => $args) {
            $result = $this->search->search($agent, $args);

            $this->assertSame(['Macbook Pro 14'], $this->names($result['products']), "office laptops, {$shape}");
            $this->assertSame('24999', $result['products'][0]['price'], "office laptops, {$shape}");
        }
    }

    /**
     * The bug itself, isolated. A catalog with no stock column must not be
     * emptied by a stock filter — that is ten products reported as none.
     */
    public function test_a_stock_filter_does_not_empty_a_catalog_that_has_no_stock_column(): void
    {
        $agent = $this->shop();

        $result = $this->search->search($agent, ['in_stock_only' => true, 'limit' => 20]);

        $this->assertSame(10, $result['count']);

        // The filter is reported as dropped rather than silently ignored: the
        // model asked for in-stock items and must not now claim these are.
        $this->assertArrayNotHasKey('in_stock_only', $result['filters']);
        $this->assertStringContainsString('does not record stock', $result['note']);
        $this->assertStringContainsString('Do NOT tell the customer these items are in stock', $result['note']);
    }

    /**
     * The other half of that: a catalog that DOES track stock still filters by
     * it. Dropping the filter everywhere would be the opposite bug.
     */
    public function test_a_catalog_that_tracks_stock_still_filters_by_it(): void
    {
        $agent = $this->shop();

        DatasetProduct::where('name', 'OMEN 16')->update(['stock' => 0]);
        DatasetProduct::where('name', '27f LED')->update(['stock' => 4]);

        $result = $this->search->search($agent, ['in_stock_only' => true, 'limit' => 20]);

        // Only the row with stock on hand. The rest are unknown, which is not
        // the same as available.
        $this->assertSame(['27f LED'], $this->names($result['products']));

        // And the out-of-stock gaming laptop is offered as a near miss rather
        // than reported as something the shop does not sell.
        $gaming = $this->search->search($agent, ['category' => 'игровой', 'in_stock_only' => true]);

        $this->assertSame(0, $gaming['count']);
        $this->assertSame(['OMEN 16'], $this->names($gaming['nearest']));
        $this->assertStringContainsString('NOT currently in stock', $gaming['nearest_note']);
    }

    /**
     * The catalog is one шкаф of laptops with one monitor in it, so a search for
     * one kind must not quietly return the other.
     */
    public function test_a_category_search_does_not_spill_into_neighbouring_categories(): void
    {
        $agent = $this->shop();

        $this->assertSame(
            ['27f LED'],
            $this->names($this->search->search($agent, ['category' => 'монитор'])['products']),
        );

        // "Ноутбук" matches every laptop including the parenthesised ones, and
        // not the monitor.
        $laptops = $this->search->search($agent, ['category' => 'ноутбук', 'limit' => 20]);

        $this->assertSame(9, $laptops['count']);
        $this->assertNotContains('27f LED', $this->names($laptops['products']));
    }
}
