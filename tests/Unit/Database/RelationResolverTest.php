<?php

namespace Tests\Unit\Database;

use App\Services\Database\Discovery\ColumnRoleResolver;
use App\Services\Database\Discovery\DiscoveredSchema;
use App\Services\Database\Discovery\Join;
use App\Services\Database\Discovery\JoinPlan;
use App\Services\Database\Discovery\RelationResolver;
use Tests\Support\FakeDatabaseDriver;
use Tests\TestCase;

/**
 * Working out how the product table connects to the rest of the schema, and
 * which of those connections a query may actually be built from.
 *
 * The rule under test throughout is that A JOIN MUST NOT MULTIPLY ROWS. A plan
 * that returns the same product once per warehouse is valid SQL and a wrong
 * answer, and the 20-row cap would then spend itself on repeats — so a join is
 * only offered onto a column the engine guarantees is unique.
 */
class RelationResolverTest extends TestCase
{
    protected RelationResolver $resolver;

    protected ColumnRoleResolver $roles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->roles = new ColumnRoleResolver;
        $this->resolver = new RelationResolver($this->roles);
    }

    /**
     * @param  array<string, array<string, string>>  $tables  table => [column => type]
     * @param  array<int, array{table: string, column: string, foreign_table: string, foreign_column: string}>  $keys
     * @param  array<int, array{table: string, column: string}>  $unique
     */
    protected function schema(array $tables, array $keys = [], array $unique = []): DiscoveredSchema
    {
        $numericTypes = ['int', 'bigint', 'decimal', 'float', 'double', 'numeric'];

        $list = [];
        $columns = [];

        foreach ($tables as $name => $spec) {
            $list[] = ['name' => $name, 'type' => 'table'];

            $columns[$name] = [];
            foreach ($spec as $column => $type) {
                $columns[$name][] = [
                    'name' => $column,
                    'type' => $type,
                    'numeric' => in_array($type, $numericTypes, true),
                ];
            }
        }

        return DiscoveredSchema::fromDriver(new FakeDatabaseDriver(
            tables: $list,
            schema: $columns,
            keys: $keys,
            unique: $unique,
        ));
    }

    /**
     * @return array{table: string, column: string, foreign_table: string, foreign_column: string}
     */
    protected function key(string $table, string $column, string $foreignTable, string $foreignColumn = 'id'): array
    {
        return ['table' => $table, 'column' => $column, 'foreign_table' => $foreignTable, 'foreign_column' => $foreignColumn];
    }

    /**
     * @return array{table: string, column: string}
     */
    protected function unique(string $table, string $column): array
    {
        return ['table' => $table, 'column' => $column];
    }

    protected function joinFor(JoinPlan $plan, string $table): ?Join
    {
        foreach ($plan->joins as $join) {
            if ($join->table === $table) {
                return $join;
            }
        }

        return null;
    }

    /** The headline case: categories in one table, stock in another. */
    protected function shop(bool $stockIsUnique = true): DiscoveredSchema
    {
        return $this->schema(
            tables: [
                'products' => ['id' => 'int', 'name' => 'varchar', 'price' => 'decimal', 'category_id' => 'int'],
                'categories' => ['id' => 'int', 'name' => 'varchar'],
                'stocks' => ['id' => 'int', 'product_id' => 'int', 'quantity' => 'int'],
            ],
            keys: [
                $this->key('products', 'category_id', 'categories'),
                $this->key('stocks', 'product_id', 'products'),
            ],
            unique: array_filter([
                $this->unique('products', 'id'),
                $this->unique('categories', 'id'),
                $this->unique('stocks', 'id'),
                $stockIsUnique ? $this->unique('stocks', 'product_id') : null,
            ]),
        );
    }

    public function test_category_and_stock_are_pulled_from_their_own_tables(): void
    {
        $schema = $this->shop();
        $roles = $this->roles->resolve($schema, 'products');

        $plan = $this->resolver->plan($schema, 'products', $roles);

        $this->assertCount(2, $plan->joins);
        $this->assertTrue($plan->supplies('category'));
        $this->assertTrue($plan->supplies('stock'));

        $fields = $plan->fields();
        // The category table's own `name` column IS the category name.
        $this->assertSame('name', $fields['category']['column']);
        $this->assertSame('quantity', $fields['stock']['column']);

        // Aliases are ours, generated in order, and never the customer's names.
        $this->assertSame(['j0', 'j1'], array_column($plan->toArray(), 'alias'));
        $this->assertTrue($plan->isValid($schema, 'products'));
        $this->assertFalse($plan->hasInferredKeys());
    }

    public function test_a_stock_table_with_a_row_per_warehouse_is_summed_rather_than_dropped(): void
    {
        // No unique key on `product_id`, so a product can have three stock rows
        // and a plain join would return it three times. This is not a broken
        // schema — it is a shop with three warehouses — and the total across
        // them is what a customer asking "is it in stock" means.
        $schema = $this->shop(stockIsUnique: false);
        $plan = $this->resolver->plan($schema, 'products', $this->roles->resolve($schema, 'products'));

        $this->assertTrue($plan->supplies('stock'));
        // The safe join is still made too: one folded edge does not cost the
        // plain one.
        $this->assertTrue($plan->supplies('category'));

        $stock = $this->joinFor($plan, 'stocks');

        $this->assertNotNull($stock);
        $this->assertSame('sum', $stock->aggregate);
        $this->assertSame(['stock' => 'quantity'], $stock->columns);
        // Grouped by the column the product is matched on: that is what makes
        // one row per product a property of the query rather than a hope.
        $this->assertSame('product_id', $stock->foreignColumn);
        $this->assertSame('id', $stock->localColumn);
        $this->assertTrue($stock->isDirect());

        // And it passes the same validation an ordinary plan does, against the
        // same schema, with no unique key anywhere in sight.
        $this->assertTrue($plan->isValid($schema, 'products'));
    }

    public function test_a_unique_stock_table_is_joined_plainly_rather_than_summed(): void
    {
        // One row per product: there is nothing to add up, and SUM would be a
        // more expensive way to say the same number.
        $schema = $this->shop();
        $plan = $this->resolver->plan($schema, 'products', $this->roles->resolve($schema, 'products'));

        $this->assertNull($this->joinFor($plan, 'stocks')?->aggregate);
    }

    public function test_a_text_stock_column_is_never_summed(): void
    {
        // "в наличии" cannot be added up. The table is left out and reported,
        // rather than folded into a total of nothing.
        $schema = $this->schema(
            tables: [
                'products' => ['id' => 'int', 'name' => 'varchar', 'price' => 'decimal'],
                'stocks' => ['id' => 'int', 'product_id' => 'int', 'quantity' => 'varchar'],
            ],
            keys: [$this->key('stocks', 'product_id', 'products')],
            unique: [$this->unique('products', 'id'), $this->unique('stocks', 'id')],
        );

        $roles = $this->roles->resolve($schema, 'products');
        $plan = $this->resolver->plan($schema, 'products', $roles);

        $this->assertFalse($plan->supplies('stock'));
        $this->assertSame(
            [['code' => 'stock_table_not_numeric', 'params' => ['table' => 'stocks', 'column' => 'quantity']]],
            $this->resolver->stockNotes($schema, 'products', $roles, $plan),
        );
    }

    public function test_an_order_table_is_never_summed_into_stock(): void
    {
        // `order_items` has exactly the shape of a stock table — a product
        // reference and a quantity — and summing it would tell a customer that
        // a sold-out product has 240 in the warehouse.
        $schema = $this->schema(
            tables: [
                'products' => ['id' => 'int', 'name' => 'varchar', 'price' => 'decimal'],
                'order_items' => ['id' => 'int', 'product_id' => 'int', 'quantity' => 'int'],
            ],
            keys: [$this->key('order_items', 'product_id', 'products')],
            unique: [$this->unique('products', 'id'), $this->unique('order_items', 'id')],
        );

        $roles = $this->roles->resolve($schema, 'products');
        $plan = $this->resolver->plan($schema, 'products', $roles);

        $this->assertFalse($plan->supplies('stock'));
        // Nor is it reported as a stock table we merely failed to read: it is
        // not one.
        $this->assertSame([], $this->resolver->stockNotes($schema, 'products', $roles, $plan));
    }

    public function test_a_stock_table_that_was_read_is_not_reported_as_missing(): void
    {
        $schema = $this->shop(stockIsUnique: false);
        $roles = $this->roles->resolve($schema, 'products');
        $plan = $this->resolver->plan($schema, 'products', $roles);

        $this->assertSame([], $this->resolver->stockNotes($schema, 'products', $roles, $plan));
    }

    public function test_a_category_table_never_supplies_the_product_name(): void
    {
        $schema = $this->shop();

        // `products` deliberately has no name of its own here, so the only name
        // column in reach is the category's.
        $stripped = $this->schema(
            tables: [
                'products' => ['id' => 'int', 'price' => 'decimal', 'category_id' => 'int'],
                'categories' => ['id' => 'int', 'name' => 'varchar'],
            ],
            keys: [$this->key('products', 'category_id', 'categories')],
            unique: [$this->unique('products', 'id'), $this->unique('categories', 'id')],
        );

        $plan = $this->resolver->plan($stripped, 'products', $this->roles->resolve($stripped, 'products'));

        // Many products share one category row, so that row describes a GROUP.
        // Reading its name as the product name would have the agent offering
        // customers a list of categories.
        $this->assertFalse($plan->supplies('name'));
        $this->assertTrue($plan->supplies('category'));
        $this->assertNull($this->resolver->nameSource($stripped, 'products'));

        $this->assertNotNull($schema);
    }

    public function test_a_one_to_one_companion_table_does_supply_the_product_name(): void
    {
        // One description row per product — that row is part OF the product,
        // not a group it belongs to.
        $schema = $this->schema(
            tables: [
                'oc_product' => ['product_id' => 'int', 'price' => 'decimal', 'quantity' => 'int'],
                'oc_product_description' => ['product_id' => 'int', 'name' => 'varchar', 'description' => 'text'],
            ],
            keys: [$this->key('oc_product_description', 'product_id', 'oc_product', 'product_id')],
            unique: [
                $this->unique('oc_product', 'product_id'),
                $this->unique('oc_product_description', 'product_id'),
            ],
        );

        $plan = $this->resolver->plan($schema, 'oc_product', $this->roles->resolve($schema, 'oc_product'));

        $this->assertTrue($plan->supplies('name'));
        $this->assertSame('name', $plan->fields()['name']['column']);
        $this->assertSame('oc_product_description', $this->resolver->nameSource($schema, 'oc_product'));
    }

    public function test_the_join_depth_cap_is_enforced(): void
    {
        // A normalized schema: the price is one hop out, the category two.
        $schema = $this->schema(
            tables: [
                'products' => ['id' => 'int', 'name' => 'varchar', 'detail_id' => 'int'],
                'details' => ['id' => 'int', 'price' => 'decimal', 'category_id' => 'int'],
                'categories' => ['id' => 'int', 'name' => 'varchar'],
            ],
            keys: [
                $this->key('products', 'detail_id', 'details'),
                $this->key('details', 'category_id', 'categories'),
            ],
            unique: [
                $this->unique('products', 'id'),
                // 1:1 — one detail row per product, so it is an extension of the
                // product rather than a group of them.
                $this->unique('products', 'detail_id'),
                $this->unique('details', 'id'),
                $this->unique('categories', 'id'),
            ],
        );

        $roles = $this->roles->resolve($schema, 'products');

        $deep = $this->resolver->plan($schema, 'products', $roles, maxDepth: 2);
        $this->assertCount(2, $deep->joins);
        $this->assertTrue($deep->supplies('price'));
        $this->assertTrue($deep->supplies('category'));
        // The second join hangs off the first, not off the product table.
        $this->assertSame('j0', $deep->joins[1]->localAlias);
        $this->assertTrue($deep->isValid($schema, 'products', 2));

        $shallow = $this->resolver->plan($schema, 'products', $roles, maxDepth: 1);
        $this->assertCount(1, $shallow->joins);
        $this->assertTrue($shallow->supplies('price'));
        // Two hops out is beyond the cap — not fetched, rather than fetched by
        // walking further into the schema than we said we would.
        $this->assertFalse($shallow->supplies('category'));
    }

    public function test_a_table_that_is_missing_nothing_gets_no_joins(): void
    {
        $schema = $this->shop();
        $everything = $this->schema(
            tables: ['v_catalog' => ['name' => 'varchar', 'price' => 'decimal', 'stock' => 'int', 'category' => 'varchar', 'description' => 'text', 'sku' => 'varchar', 'url' => 'varchar']],
        );

        $plan = $this->resolver->plan($everything, 'v_catalog', $this->roles->resolve($everything, 'v_catalog'));

        // A flattened view needs nothing joined, which is part of why a view is
        // often the best candidate in the first place.
        $this->assertTrue($plan->isEmpty());
        $this->assertNotNull($schema);
    }

    public function test_a_table_outside_the_schema_gets_no_joins(): void
    {
        $schema = $this->shop();

        $this->assertTrue($this->resolver->plan($schema, 'information_schema.tables', $this->roles->resolve($schema, 'products'))->isEmpty());
    }

    public function test_relationships_are_inferred_when_the_schema_declares_none(): void
    {
        $schema = $this->schema(
            tables: [
                'products' => ['id' => 'int', 'name' => 'varchar', 'price' => 'decimal', 'category_id' => 'int'],
                'categories' => ['id' => 'int', 'name' => 'varchar'],
            ],
            keys: [],
            unique: [$this->unique('products', 'id'), $this->unique('categories', 'id')],
        );

        $this->assertFalse($schema->hasDeclaredForeignKeys());

        // The legacy/MyISAM case: `category_id` next to a `categories` table is
        // still a relationship, just one we worked out rather than were told.
        $widened = $this->resolver->infer($schema);

        $keys = $widened->keysFrom('products');
        $this->assertCount(1, $keys);
        $this->assertSame('categories', $keys[0]->foreignTable);
        $this->assertSame('id', $keys[0]->foreignColumn);
        $this->assertFalse($keys[0]->declared);
        // Still false: inferred keys are not facts, and the confidence the owner
        // is shown has to keep reflecting that.
        $this->assertFalse($widened->hasDeclaredForeignKeys());

        $plan = $this->resolver->plan($widened, 'products', $this->roles->resolve($widened, 'products'));
        $this->assertTrue($plan->supplies('category'));
        $this->assertTrue($plan->hasInferredKeys());
    }

    public function test_an_inferred_relationship_never_overrides_a_declared_one(): void
    {
        $schema = $this->schema(
            tables: [
                'products' => ['id' => 'int', 'name' => 'varchar', 'category_id' => 'int'],
                'categories' => ['id' => 'int', 'name' => 'varchar'],
                'category' => ['id' => 'int', 'name' => 'varchar'],
            ],
            keys: [$this->key('products', 'category_id', 'categories')],
            unique: [$this->unique('products', 'id'), $this->unique('categories', 'id'), $this->unique('category', 'id')],
        );

        $keys = $this->resolver->infer($schema)->keysFrom('products');

        $this->assertCount(1, $keys);
        $this->assertSame('categories', $keys[0]->foreignTable);
        $this->assertTrue($keys[0]->declared);
    }

    public function test_an_ambiguous_name_infers_nothing(): void
    {
        // Two tables answer to `category_id`. Guessing between them is exactly
        // the silent choice this feature exists not to make.
        $schema = $this->schema(
            tables: [
                'products' => ['id' => 'int', 'name' => 'varchar', 'category_id' => 'int'],
                'categories' => ['id' => 'int', 'name' => 'varchar'],
                'category' => ['id' => 'int', 'name' => 'varchar'],
            ],
            unique: [$this->unique('products', 'id'), $this->unique('categories', 'id'), $this->unique('category', 'id')],
        );

        $this->assertSame([], $this->resolver->infer($schema)->keysFrom('products'));
    }

    public function test_inference_requires_the_target_column_to_be_unique(): void
    {
        $schema = $this->schema(
            tables: [
                'products' => ['id' => 'int', 'name' => 'varchar', 'category_id' => 'int'],
                'categories' => ['id' => 'int', 'name' => 'varchar'],
            ],
            // `categories.id` carries no unique key, so a join on it could match
            // several rows and repeat the product.
            unique: [$this->unique('products', 'id')],
        );

        $this->assertSame([], $this->resolver->infer($schema)->keysFrom('products'));
    }

    public function test_inference_ignores_columns_that_are_not_identifiers(): void
    {
        $schema = $this->schema(
            tables: [
                'products' => ['id' => 'int', 'name' => 'varchar', 'category_id' => 'varchar', 'paid' => 'int'],
                'categories' => ['id' => 'int', 'name' => 'varchar'],
            ],
            unique: [$this->unique('products', 'id'), $this->unique('categories', 'id')],
        );

        // A textual `category_id` is not a key we will join on, and `paid` only
        // looks like one if you match on substrings rather than names.
        $this->assertSame([], $this->resolver->infer($schema)->keysFrom('products'));
    }

    public function test_it_reports_which_fields_the_agent_will_end_up_seeing(): void
    {
        $schema = $this->shop();
        $roles = $this->roles->resolve($schema, 'products');
        $plan = $this->resolver->plan($schema, 'products', $roles);

        $covered = $this->resolver->coveredFields($roles, $plan);

        $this->assertContains('name', $covered);
        $this->assertContains('price', $covered);
        $this->assertContains('category', $covered);
        $this->assertContains('stock', $covered);
        $this->assertNotContains('url', $covered);
    }
}
