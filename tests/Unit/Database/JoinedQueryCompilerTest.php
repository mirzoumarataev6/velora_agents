<?php

namespace Tests\Unit\Database;

use App\Services\Database\ColumnRef;
use App\Services\Database\ConnectionConfig;
use App\Services\Database\Discovery\DiscoveredSchema;
use App\Services\Database\Discovery\Join;
use App\Services\Database\Discovery\JoinPlan;
use App\Services\Database\MySqlDriver;
use App\Services\Database\PostgresDriver;
use App\Services\Database\ProductQuery;
use InvalidArgumentException;
use Tests\Support\FakeDatabaseDriver;
use Tests\TestCase;

/**
 * What a JOINED search actually puts on the wire.
 *
 * The single-table compiler already had four properties worth having: only
 * SELECT is produced, every value is a placeholder, every identifier is quoted,
 * and the row cap and timeout are always applied. Joins widen the SQL, so each
 * of those is re-asserted here rather than assumed to have survived — that is
 * the whole point of the file.
 */
class JoinedQueryCompilerTest extends TestCase
{
    protected function mysql(): MySqlDriver
    {
        return new MySqlDriver(new ConnectionConfig('mysql', 'db.example.com', 3306, 'shop', 'readonly', 'secret'));
    }

    protected function postgres(): PostgresDriver
    {
        return new PostgresDriver(new ConnectionConfig('pgsql', 'db.example.com', 5432, 'shop', 'readonly', 'secret'));
    }

    protected function schema(): DiscoveredSchema
    {
        return DiscoveredSchema::fromDriver(new FakeDatabaseDriver(
            tables: [
                ['name' => 'products', 'type' => 'table'],
                ['name' => 'categories', 'type' => 'table'],
                ['name' => 'stocks', 'type' => 'table'],
            ],
            schema: [
                'products' => [
                    ['name' => 'id', 'type' => 'int', 'numeric' => true],
                    ['name' => 'title', 'type' => 'varchar', 'numeric' => false],
                    ['name' => 'price', 'type' => 'decimal', 'numeric' => true],
                    ['name' => 'category_id', 'type' => 'int', 'numeric' => true],
                ],
                'categories' => [
                    ['name' => 'id', 'type' => 'int', 'numeric' => true],
                    ['name' => 'name', 'type' => 'varchar', 'numeric' => false],
                ],
                'stocks' => [
                    ['name' => 'product_id', 'type' => 'int', 'numeric' => true],
                    ['name' => 'quantity', 'type' => 'int', 'numeric' => true],
                ],
            ],
            unique: [
                ['table' => 'products', 'column' => 'id'],
                ['table' => 'categories', 'column' => 'id'],
                ['table' => 'stocks', 'column' => 'product_id'],
            ],
        ));
    }

    protected function plan(): JoinPlan
    {
        return new JoinPlan([
            new Join('categories', 'j0', '', 'category_id', 'id', ['category' => 'name']),
            new Join('stocks', 'j1', '', 'id', 'product_id', ['stock' => 'quantity']),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function spec(array $overrides = []): ProductQuery
    {
        return new ProductQuery(
            table: 'products',
            columns: $overrides['columns'] ?? [
                'name' => 'title',
                'price' => 'price',
                'category' => new ColumnRef('name', 'j0'),
                'stock' => new ColumnRef('quantity', 'j1'),
            ],
            conditions: $overrides['conditions'] ?? [],
            textGroups: $overrides['textGroups'] ?? [],
            orderColumn: $overrides['orderColumn'] ?? null,
            orderDirection: $overrides['orderDirection'] ?? 'asc',
            limit: $overrides['limit'] ?? 5,
            joins: $overrides['joins'] ?? $this->plan(),
            schema: $this->schema(),
        );
    }

    public function test_mysql_composes_the_joins_itself_with_every_identifier_quoted(): void
    {
        [$sql, $bindings] = $this->mysql()->compile($this->spec([
            'textGroups' => [['columns' => ['title'], 'value' => 'куртка']],
            'conditions' => [['column' => new ColumnRef('quantity', 'j1'), 'operator' => '>', 'value' => 0]],
            'orderColumn' => 'price',
            'limit' => 7,
        ]));

        // The product table gets OUR alias, not one from the customer's schema.
        $this->assertStringContainsString('FROM `products` AS `t`', $sql);
        $this->assertStringContainsString('LEFT JOIN `categories` AS `j0` ON `t`.`category_id` = `j0`.`id`', $sql);
        $this->assertStringContainsString('LEFT JOIN `stocks` AS `j1` ON `t`.`id` = `j1`.`product_id`', $sql);

        // Selected columns say which table they came from, so a `name` on two
        // tables can never be confused for one another.
        $this->assertStringContainsString('`t`.`title` AS `name`', $sql);
        $this->assertStringContainsString('`j0`.`name` AS `category`', $sql);
        $this->assertStringContainsString('`j1`.`quantity` AS `stock`', $sql);

        // The filters, the ordering and the cap all still work — on the joined
        // columns as well as the product table's own.
        $this->assertStringContainsString('LOWER(CAST(`t`.`title` AS CHAR)) LIKE ?', $sql);
        $this->assertStringContainsString('`j1`.`quantity` > ?', $sql);
        $this->assertStringContainsString('ORDER BY `t`.`price` IS NULL, `t`.`price` ASC', $sql);
        $this->assertStringEndsWith('LIMIT 7', $sql);

        $this->assertSame(['%куртка%', 0], $bindings);
        $this->assertStringNotContainsString('куртка', $sql);
    }

    public function test_postgres_composes_the_same_query_in_its_own_dialect(): void
    {
        [$sql, $bindings] = $this->postgres()->compile($this->spec([
            'textGroups' => [['columns' => [new ColumnRef('name', 'j0')], 'value' => 'зима']],
            'orderColumn' => 'price',
            'orderDirection' => 'desc',
        ]));

        $this->assertStringContainsString('FROM "products" AS "t"', $sql);
        $this->assertStringContainsString('LEFT JOIN "categories" AS "j0" ON "t"."category_id" = "j0"."id"', $sql);
        $this->assertStringContainsString('LOWER(CAST("j0"."name" AS TEXT)) LIKE ?', $sql);
        $this->assertStringContainsString('ORDER BY "t"."price" DESC NULLS LAST', $sql);
        $this->assertSame(['%зима%'], $bindings);
    }

    public function test_a_join_is_always_a_left_join(): void
    {
        [$sql] = $this->mysql()->compile($this->spec());

        // A product with no category row is still a product. An INNER join would
        // silently drop it from the catalog the agent can see.
        $this->assertStringNotContainsString('INNER JOIN', $sql);
        $this->assertSame(2, substr_count($sql, 'LEFT JOIN'));
    }

    public function test_a_joined_query_is_still_select_only(): void
    {
        foreach ([$this->mysql(), $this->postgres()] as $driver) {
            [$sql] = $driver->compile($this->spec());

            $this->assertMatchesRegularExpression('/^\s*SELECT\s/i', $sql);
            $this->assertStringNotContainsString(';', $sql);
            $this->assertDoesNotMatchRegularExpression(
                '/\b(INSERT|UPDATE|DELETE|DROP|ALTER|TRUNCATE|CREATE|GRANT|REVOKE|INTO\s+OUTFILE)\b/i',
                $sql,
                $driver->engine(),
            );
        }
    }

    public function test_a_joined_query_still_carries_the_row_cap_and_the_timeout(): void
    {
        config(['agent.database.query_timeout_seconds' => 3]);

        [$mysql] = $this->mysql()->compile($this->spec(['limit' => 2000]));
        [$postgres] = $this->postgres()->compile($this->spec(['limit' => 2000]));

        // The cap is re-clamped in the compiler, not merely by the caller.
        $this->assertStringEndsWith('LIMIT 1000', $mysql);
        $this->assertStringEndsWith('LIMIT 1000', $postgres);

        // MySQL carries its ceiling in the statement itself; Postgres sets it on
        // the session, which is asserted where the session statements are.
        $this->assertStringContainsString('MAX_EXECUTION_TIME(3000)', $mysql);
    }

    public function test_a_query_with_joins_cannot_be_built_without_a_schema_to_check_them_against(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ProductQuery(
            table: 'products',
            columns: ['name' => 'title', 'category' => new ColumnRef('name', 'j0')],
            joins: $this->plan(),
            // No schema — so nothing could confirm that `categories` or `j0`.`id`
            // are real. The query refuses to exist rather than trusting them.
            schema: null,
        );
    }

    public function test_a_column_on_a_table_that_is_not_in_the_query_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->spec(['columns' => ['name' => 'title', 'category' => new ColumnRef('name', 'j9')]]);
    }

    public function test_a_condition_on_a_joined_column_outside_the_mapping_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // `j0`.`id` is a real column on a real joined table — and it is not one
        // the mapping selected, so it may not be filtered on either.
        $this->spec(['conditions' => [['column' => new ColumnRef('id', 'j0'), 'operator' => '=', 'value' => 1]]]);
    }

    public function test_the_same_column_name_on_two_tables_is_not_confused_for_one(): void
    {
        // `products.title` is mapped; `categories.name` is mapped under j0. A
        // bare "name" must not let a condition reach the product table's, nor
        // vice versa — membership is by (table, column), not by name.
        $this->expectException(InvalidArgumentException::class);

        $this->spec([
            'columns' => ['name' => 'title', 'category' => new ColumnRef('name', 'j0')],
            'conditions' => [['column' => 'name', 'operator' => '=', 'value' => 'x']],
        ]);
    }

    public function test_a_join_naming_something_outside_the_schema_is_refused_at_query_time(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->spec([
            'joins' => new JoinPlan([new Join('users', 'j0', '', 'category_id', 'id', ['category' => 'name'])]),
            'columns' => ['name' => 'title', 'category' => new ColumnRef('name', 'j0')],
        ]);
    }

    public function test_a_single_table_query_compiles_exactly_as_it_did_before_joins_existed(): void
    {
        $plain = new ProductQuery(
            table: 'products',
            columns: ['name' => 'title', 'price' => 'price'],
            conditions: [['column' => 'price', 'operator' => '<=', 'value' => 500]],
            orderColumn: 'price',
            limit: 3,
        );

        [$sql] = $this->mysql()->compile($plain);

        // No alias, no join, nothing qualified: a working connection's SQL does
        // not change because joins became possible elsewhere.
        $this->assertStringContainsString('`title` AS `name`', $sql);
        $this->assertStringContainsString('FROM `products` WHERE', $sql);
        $this->assertStringNotContainsString(' AS `t`', $sql);
        $this->assertStringNotContainsString('JOIN', $sql);
        $this->assertStringContainsString('`price` <= ?', $sql);
    }
}
