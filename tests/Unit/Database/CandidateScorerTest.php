<?php

namespace Tests\Unit\Database;

use App\Services\Database\Discovery\CandidateScorer;
use App\Services\Database\Discovery\ColumnRoleResolver;
use App\Services\Database\Discovery\DiscoveredSchema;
use App\Services\Database\Discovery\RelationResolver;
use App\Services\Database\Discovery\TableCandidate;
use Tests\Support\FakeDatabaseDriver;
use Tests\TestCase;

/**
 * Which table the platform decides holds the products, with no model involved.
 *
 * The cases here are the shapes real shop databases actually come in: a tidy
 * Laravel-style schema, a Russian-named one, a legacy schema with no foreign
 * keys at all, OpenCart's split product/description pair, and the retail-versus-
 * wholesale ambiguity that must NOT be resolved by silently picking one.
 *
 * Scores themselves are deliberately not asserted — they are an implementation
 * detail, and a test that pins them would have to be rewritten every time a
 * weight is tuned. What is asserted is what the owner would see: which table
 * wins, which are never offered, how sure we claim to be, and why.
 */
class CandidateScorerTest extends TestCase
{
    protected CandidateScorer $scorer;

    protected function setUp(): void
    {
        parent::setUp();

        $roles = new ColumnRoleResolver;

        $this->scorer = new CandidateScorer($roles, new RelationResolver($roles));
    }

    /**
     * Build a schema from a compact description: table => [column => type].
     *
     * @param  array<string, array<string, mixed>>  $tables
     * @param  array<int, array{table: string, column: string, foreign_table: string, foreign_column: string}>  $keys
     * @param  array<string, int>  $counts
     */
    protected function schema(array $tables, array $keys = [], array $counts = [], ?array $unique = null): DiscoveredSchema
    {
        $numericTypes = ['int', 'bigint', 'decimal', 'float', 'double', 'numeric'];

        $list = [];
        $columns = [];
        $keyColumns = [];

        foreach ($tables as $name => $spec) {
            $list[] = ['name' => $name, 'type' => $spec['type'] ?? 'table'];

            $columns[$name] = [];
            foreach ($spec['columns'] as $column => $type) {
                $columns[$name][] = [
                    'name' => $column,
                    'type' => $type,
                    'numeric' => in_array($type, $numericTypes, true),
                ];
            }

            // Unless a test says otherwise, every table has a primary key on
            // `id` — which is what real schemas look like, and what decides
            // whether a join onto them can repeat a product.
            foreach (['id', 'Код'] as $primary) {
                if (isset($spec['columns'][$primary])) {
                    $keyColumns[] = ['table' => $name, 'column' => $primary];
                }
            }
        }

        return DiscoveredSchema::fromDriver(new FakeDatabaseDriver(
            tables: $list,
            schema: $columns,
            keys: $keys,
            counts: $counts,
            unique: $unique ?? $keyColumns,
        ));
    }

    /**
     * @param  array<int, array{table: string, column: string, foreign_table: string, foreign_column: string}>  $keys
     */
    protected function key(string $table, string $column, string $foreignTable, string $foreignColumn = 'id'): array
    {
        return ['table' => $table, 'column' => $column, 'foreign_table' => $foreignTable, 'foreign_column' => $foreignColumn];
    }

    /** A tidy, conventionally named shop database. */
    protected function conventionalShop(bool $withKeys = true): DiscoveredSchema
    {
        return $this->schema(
            tables: [
                'products' => ['columns' => ['id' => 'int', 'name' => 'varchar', 'price' => 'decimal', 'qty' => 'int', 'category_id' => 'int']],
                'categories' => ['columns' => ['id' => 'int', 'name' => 'varchar']],
                'stocks' => ['columns' => ['id' => 'int', 'product_id' => 'int', 'quantity' => 'int']],
                'users' => ['columns' => ['id' => 'int', 'email' => 'varchar', 'password' => 'varchar']],
                'orders' => ['columns' => ['id' => 'int', 'product_name' => 'varchar', 'price' => 'decimal', 'quantity' => 'int']],
                'migrations' => ['columns' => ['id' => 'int', 'migration' => 'varchar', 'batch' => 'int']],
            ],
            keys: $withKeys ? [
                $this->key('products', 'category_id', 'categories'),
                $this->key('stocks', 'product_id', 'products'),
            ] : [],
            counts: ['products' => 8400, 'categories' => 26, 'orders' => 51000],
        );
    }

    /**
     * @param  array<int, TableCandidate>  $candidates
     * @return string[]
     */
    protected function names(array $candidates): array
    {
        return array_map(fn (TableCandidate $candidate) => $candidate->table, $candidates);
    }

    /**
     * @return string[]
     */
    protected function reasonCodes(TableCandidate $candidate): array
    {
        return array_column($candidate->reasons, 'code');
    }

    public function test_a_conventional_schema_is_decided_outright_and_explains_itself(): void
    {
        $ranked = $this->scorer->rank($this->conventionalShop());
        $winner = $ranked[0];

        $this->assertSame('products', $winner->table);
        $this->assertSame(TableCandidate::HIGH, $winner->confidence);
        $this->assertFalse($this->scorer->isAmbiguous($ranked));

        // Every reason is something the owner can check for themselves.
        $this->assertContains('table_name_matches', $this->reasonCodes($winner));
        $this->assertContains('has_name_column', $this->reasonCodes($winner));
        $this->assertContains('has_price_column', $this->reasonCodes($winner));
        $this->assertContains('linked_to_categories', $this->reasonCodes($winner));
        $this->assertContains('referenced_by_others', $this->reasonCodes($winner));

        // The proposed mapping is already the shape the owner would confirm.
        $this->assertSame('name', $winner->roles->column('name'));
        $this->assertSame('price', $winner->roles->column('price'));
        $this->assertSame('qty', $winner->roles->column('stock'));
        $this->assertSame(8400, $winner->rowCount);
    }

    public function test_only_the_products_table_is_ever_put_in_front_of_the_owner(): void
    {
        $shortlist = $this->names($this->scorer->shortlist($this->scorer->rank($this->conventionalShop())));

        $this->assertSame(['products'], $shortlist);
    }

    public function test_an_orders_table_is_never_offered_even_though_it_has_a_name_a_price_and_a_quantity(): void
    {
        $ranked = $this->scorer->rank($this->conventionalShop());

        // The dangerous false positive: answering "what do you sell" out of
        // somebody else's past purchase. It has every column a catalog has, and
        // 51,000 rows, and it still must not be offered.
        $this->assertNotContains('orders', $this->names($this->scorer->shortlist($ranked, 5)));

        $orders = $this->candidate($ranked, 'orders');
        $this->assertNotNull($orders);
        $this->assertContains('looks_like_orders', $this->reasonCodes($orders));
        $this->assertLessThan(CandidateScorer::PLAUSIBLE_SCORE, $orders->score);
    }

    public function test_lookup_and_service_tables_are_not_offered_as_the_catalog(): void
    {
        $shortlist = $this->names($this->scorer->shortlist($this->scorer->rank($this->conventionalShop()), 5));

        // `categories` and `stocks` both have a name column and are referenced
        // by the catalog. They are what discovery will JOIN, not what it picks.
        $this->assertNotContains('categories', $shortlist);
        $this->assertNotContains('stocks', $shortlist);
        $this->assertNotContains('migrations', $shortlist);
    }

    public function test_a_table_with_no_way_to_name_a_product_is_not_a_candidate_at_all(): void
    {
        $ranked = $this->scorer->rank($this->conventionalShop());

        // Not ranked last — absent. A table the agent could not name a single
        // product from is not a weak candidate.
        $this->assertNull($this->candidate($ranked, 'users'));
        $this->assertNull($this->candidate($ranked, 'migrations'));
    }

    public function test_a_russian_named_schema_is_recognized(): void
    {
        $schema = $this->schema(
            tables: [
                'Товары' => ['columns' => ['Код' => 'int', 'Название' => 'varchar', 'Цена' => 'decimal', 'Остаток' => 'int', 'Категория' => 'int']],
                'Категории' => ['columns' => ['Код' => 'int', 'Наименование' => 'varchar']],
            ],
            keys: [$this->key('Товары', 'Категория', 'Категории', 'Код')],
        );

        $winner = $this->scorer->rank($schema)[0];

        $this->assertSame('Товары', $winner->table);
        $this->assertSame('Название', $winner->roles->column('name'));
        $this->assertSame('Цена', $winner->roles->column('price'));
        $this->assertSame('Остаток', $winner->roles->column('stock'));
        $this->assertContains('linked_to_categories', $this->reasonCodes($winner));
    }

    public function test_a_transliterated_table_name_is_recognized(): void
    {
        // Latin identifiers over a Russian-language shop — the usual shape of a
        // 1C or self-built export. `spravochnik` has a name column too and would
        // win on shape alone; what separates them is what the table is called.
        $schema = $this->schema([
            'tovary' => ['columns' => ['id' => 'int', 'name' => 'varchar', 'price' => 'decimal']],
            'spravochnik' => ['columns' => ['id' => 'int', 'name' => 'varchar']],
        ]);

        $this->assertSame('tovary', $this->scorer->rank($schema)[0]->table);
    }

    public function test_a_legacy_schema_with_no_declared_keys_still_finds_the_catalog_but_claims_less(): void
    {
        $ranked = $this->scorer->rank($this->conventionalShop(withKeys: false));

        $this->assertSame('products', $ranked[0]->table);
        // Nothing in the schema confirms the reading, so it is not sold as
        // certain — this is the MyISAM/CMS-export case.
        $this->assertSame(TableCandidate::MEDIUM, $ranked[0]->confidence);
        $this->assertNotContains('linked_to_categories', $this->reasonCodes($ranked[0]));
    }

    public function test_retail_and_wholesale_are_both_surfaced_rather_than_one_being_picked(): void
    {
        $schema = $this->schema(
            tables: [
                'products_retail' => ['columns' => ['id' => 'int', 'name' => 'varchar', 'price' => 'decimal', 'qty' => 'int', 'category_id' => 'int']],
                'products_wholesale' => ['columns' => ['id' => 'int', 'name' => 'varchar', 'price' => 'decimal', 'qty' => 'int', 'category_id' => 'int']],
                'categories' => ['columns' => ['id' => 'int', 'name' => 'varchar']],
            ],
            keys: [
                $this->key('products_retail', 'category_id', 'categories'),
                $this->key('products_wholesale', 'category_id', 'categories'),
            ],
        );

        $ranked = $this->scorer->rank($schema);

        $this->assertTrue($this->scorer->isAmbiguous($ranked));

        // Both go to the owner. Quoting wholesale prices to retail customers is
        // exactly the failure a silent pick would cause.
        $shortlist = $this->names($this->scorer->shortlist($ranked));
        $this->assertContains('products_retail', $shortlist);
        $this->assertContains('products_wholesale', $shortlist);

        // And the leader stops claiming to be certain, because it is not: the
        // confidence is a statement about the answer, not about the table.
        $this->assertNotSame(TableCandidate::HIGH, $ranked[0]->confidence);
    }

    public function test_a_close_ranking_is_not_reported_when_the_leader_is_clear(): void
    {
        $ranked = $this->scorer->rank($this->conventionalShop());

        $this->assertFalse($this->scorer->isAmbiguous($ranked));
        $this->assertSame(TableCandidate::HIGH, $ranked[0]->confidence);
    }

    public function test_a_well_named_view_is_ranked_fairly_and_flagged_as_one(): void
    {
        $schema = $this->schema([
            'v_catalog' => ['type' => 'view', 'columns' => ['name' => 'varchar', 'price' => 'decimal', 'stock' => 'int', 'category' => 'varchar']],
            'tbl_data' => ['columns' => ['id' => 'int', 'title' => 'varchar', 'val' => 'decimal']],
        ]);

        $winner = $this->scorer->rank($schema)[0];

        // Someone built this view to flatten their schema for exactly this kind
        // of reading. It should not lose to a table for not being one.
        $this->assertSame('v_catalog', $winner->table);
        $this->assertTrue($winner->isView());
        $this->assertContains('is_view', $this->reasonCodes($winner));
    }

    /**
     * The split-catalog shape: price and quantity on the product row, the name
     * on a companion table.
     *
     * @param  array<int, array{table: string, column: string}>  $unique
     */
    protected function splitCatalog(array $unique): DiscoveredSchema
    {
        return $this->schema(
            tables: [
                'oc_product' => ['columns' => ['product_id' => 'int', 'model' => 'varchar', 'price' => 'decimal', 'quantity' => 'int']],
                'oc_product_description' => ['columns' => ['product_id' => 'int', 'name' => 'varchar', 'description' => 'text']],
            ],
            keys: [$this->key('oc_product_description', 'product_id', 'oc_product', 'product_id')],
            unique: $unique,
        );
    }

    public function test_a_catalog_split_across_two_tables_names_the_right_primary(): void
    {
        // One description row per product: the join can only ever match once, so
        // the name can be fetched from next door.
        $ranked = $this->scorer->rank($this->splitCatalog([
            ['table' => 'oc_product', 'column' => 'product_id'],
            ['table' => 'oc_product_description', 'column' => 'product_id'],
        ]));

        $winner = $ranked[0];

        $this->assertSame('oc_product', $winner->table);
        $this->assertSame('oc_product_description', $winner->nameFrom);
        $this->assertContains('name_column_in_related', $this->reasonCodes($winner));

        // Leaning on a related table for the product name is a weaker reading
        // than having one, and says so.
        $this->assertSame(TableCandidate::LOW, $winner->confidence);
    }

    public function test_a_table_is_not_credited_for_a_name_that_could_not_be_fetched_without_repeating_products(): void
    {
        // The real OpenCart shape: the description table's key is
        // (product_id, language_id), so `product_id` alone is NOT unique and a
        // join would return the product once per language.
        $ranked = $this->scorer->rank($this->splitCatalog([
            ['table' => 'oc_product', 'column' => 'product_id'],
        ]));

        // `oc_product` has no product name of its own and no safe way to reach
        // one, so it is not offered at all rather than offered with a join that
        // would quietly duplicate every row.
        $this->assertNull($this->candidate($ranked, 'oc_product'));

        // The table that DOES hold the names is still a candidate — the owner
        // gets a working single-table answer instead of a broken composed one.
        $this->assertSame('oc_product_description', $ranked[0]->table);
    }

    public function test_a_pure_link_table_is_not_offered(): void
    {
        $schema = $this->schema(
            tables: [
                'products' => ['columns' => ['id' => 'int', 'name' => 'varchar', 'price' => 'decimal']],
                'categories' => ['columns' => ['id' => 'int', 'name' => 'varchar']],
                'product_category' => ['columns' => ['product_id' => 'int', 'category_id' => 'int']],
            ],
            keys: [
                $this->key('product_category', 'product_id', 'products'),
                $this->key('product_category', 'category_id', 'categories'),
            ],
        );

        $ranked = $this->scorer->rank($schema);

        $this->assertSame('products', $ranked[0]->table);
        // Not merely ranked low: a table of nothing but keys has no product name
        // and no unique-keyed route to one, so it is not a candidate at all.
        $this->assertNull($this->candidate($ranked, 'product_category'));
    }

    public function test_a_narrow_all_numeric_table_is_penalised_even_when_it_can_reach_a_name(): void
    {
        // One row per product and nothing but numbers on it: plumbing hung off
        // the catalog, not the catalog.
        $schema = $this->schema(
            tables: [
                'products' => ['columns' => ['id' => 'int', 'name' => 'varchar', 'price' => 'decimal']],
                'product_stock' => ['columns' => ['product_id' => 'int', 'quantity' => 'int']],
            ],
            keys: [$this->key('product_stock', 'product_id', 'products')],
            unique: [
                ['table' => 'products', 'column' => 'id'],
                ['table' => 'product_stock', 'column' => 'product_id'],
            ],
        );

        $ranked = $this->scorer->rank($schema);

        $this->assertSame('products', $ranked[0]->table);

        $link = $this->candidate($ranked, 'product_stock');
        $this->assertNotNull($link);
        $this->assertContains('looks_like_link_table', $this->reasonCodes($link));
        $this->assertNotContains('product_stock', $this->names($this->scorer->shortlist($ranked, 5)));
    }

    public function test_a_text_price_is_accepted_but_reported_as_a_limitation(): void
    {
        $schema = $this->schema([
            'products' => ['columns' => ['id' => 'int', 'name' => 'varchar', 'price' => 'varchar']],
        ]);

        $winner = $this->scorer->rank($schema)[0];

        $this->assertSame('price', $winner->roles->column('price'));
        $this->assertFalse($winner->roles->hasNumericPrice());
        // The owner is told the "cheaper than …" filter will not work, rather
        // than finding out from a customer.
        $this->assertContains('price_not_numeric', $this->reasonCodes($winner));
        $this->assertNotContains('has_price_column', $this->reasonCodes($winner));
        $this->assertSame(TableCandidate::MEDIUM, $winner->confidence);
    }

    public function test_ranking_the_same_schema_twice_gives_the_same_order(): void
    {
        $schema = $this->schema([
            'items_a' => ['columns' => ['id' => 'int', 'name' => 'varchar', 'price' => 'decimal']],
            'items_b' => ['columns' => ['id' => 'int', 'name' => 'varchar', 'price' => 'decimal']],
            'items_c' => ['columns' => ['id' => 'int', 'name' => 'varchar', 'price' => 'decimal']],
        ]);

        // Identical tables tie on score; the order still has to be stable, or
        // the same database would propose a different table on every re-scan.
        $this->assertSame(['items_a', 'items_b', 'items_c'], $this->names($this->scorer->rank($schema)));
        $this->assertSame(['items_a', 'items_b', 'items_c'], $this->names($this->scorer->rank($schema)));
    }

    public function test_an_empty_schema_produces_no_candidates(): void
    {
        $ranked = $this->scorer->rank($this->schema([]));

        $this->assertSame([], $ranked);
        $this->assertFalse($this->scorer->isAmbiguous($ranked));
        $this->assertSame([], $this->scorer->shortlist($ranked));
    }

    /**
     * @param  array<int, TableCandidate>  $ranked
     */
    protected function candidate(array $ranked, string $table): ?TableCandidate
    {
        foreach ($ranked as $candidate) {
            if ($candidate->table === $table) {
                return $candidate;
            }
        }

        return null;
    }
}
