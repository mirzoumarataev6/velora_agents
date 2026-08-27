<?php

namespace Tests\Unit\Database;

use App\Services\Database\ConnectionConfig;
use App\Services\Database\MySqlDriver;
use App\Services\Database\PostgresDriver;
use App\Services\Database\ProductQuery;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * What the real drivers actually put on the wire.
 *
 * Compilation is pure — no server is involved — so the security properties this
 * feature rests on can be asserted directly rather than argued about: values are
 * always placeholders, identifiers are always quoted, and nothing but SELECT can
 * be produced.
 */
class QueryCompilerTest extends TestCase
{
    protected function mysql(): MySqlDriver
    {
        return new MySqlDriver(new ConnectionConfig('mysql', 'db.example.com', 3306, 'shop', 'readonly', 'secret'));
    }

    protected function postgres(): PostgresDriver
    {
        return new PostgresDriver(new ConnectionConfig('pgsql', 'db.example.com', 5432, 'shop', 'readonly', 'secret'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function spec(array $overrides = []): ProductQuery
    {
        return new ProductQuery(
            table: $overrides['table'] ?? 'products',
            columns: $overrides['columns'] ?? ['name' => 'title', 'price' => 'price', 'stock' => 'qty'],
            conditions: $overrides['conditions'] ?? [],
            textGroups: $overrides['textGroups'] ?? [],
            orderColumn: $overrides['orderColumn'] ?? null,
            orderDirection: $overrides['orderDirection'] ?? 'asc',
            limit: $overrides['limit'] ?? 5,
        );
    }

    public function test_mysql_compiles_a_parameterized_select_with_quoted_identifiers(): void
    {
        [$sql, $bindings] = $this->mysql()->compile($this->spec([
            'textGroups' => [['columns' => ['title'], 'value' => 'куртка']],
            'conditions' => [['column' => 'price', 'operator' => '<=', 'value' => 7321]],
            'orderColumn' => 'price',
            'limit' => 3,
        ]));

        $this->assertStringStartsWith('SELECT /*+ MAX_EXECUTION_TIME(', $sql);
        $this->assertStringContainsString('`title` AS `name`', $sql);
        $this->assertStringContainsString('FROM `products`', $sql);
        $this->assertStringContainsString('LOWER(CAST(`title` AS CHAR)) LIKE ?', $sql);
        $this->assertStringContainsString('`price` <= ?', $sql);
        // Nulls last, matching the uploaded catalog's ordering.
        $this->assertStringContainsString('ORDER BY `price` IS NULL, `price` ASC', $sql);
        $this->assertStringEndsWith('LIMIT 3', $sql);

        // Every value is a placeholder. No search term or price reaches the SQL.
        $this->assertSame(['%куртка%', 7321], $bindings);
        $this->assertStringNotContainsString('куртка', $sql);
        $this->assertStringNotContainsString('7321', $sql);
    }

    public function test_postgres_compiles_the_same_query_with_its_own_dialect(): void
    {
        [$sql, $bindings] = $this->postgres()->compile($this->spec([
            'textGroups' => [['columns' => ['title'], 'value' => 'jacket']],
            'orderColumn' => 'price',
            'orderDirection' => 'desc',
        ]));

        $this->assertStringContainsString('"title" AS "name"', $sql);
        $this->assertStringContainsString('LOWER(CAST("title" AS TEXT)) LIKE ?', $sql);
        $this->assertStringContainsString('ORDER BY "price" DESC NULLS LAST', $sql);
        // No MySQL optimizer hint: Postgres gets its ceiling from statement_timeout.
        $this->assertStringNotContainsString('MAX_EXECUTION_TIME', $sql);
        $this->assertSame(['%jacket%'], $bindings);
    }

    public function test_terms_are_and_ed_and_columns_are_or_ed(): void
    {
        [$sql] = $this->mysql()->compile($this->spec([
            'columns' => ['name' => 'title', 'description' => 'descr'],
            'textGroups' => [
                ['columns' => ['title', 'descr'], 'value' => 'red'],
                ['columns' => ['title', 'descr'], 'value' => 'jacket'],
            ],
        ]));

        $this->assertStringContainsString(
            'WHERE (LOWER(CAST(`title` AS CHAR)) LIKE ? OR LOWER(CAST(`descr` AS CHAR)) LIKE ?) '
                .'AND (LOWER(CAST(`title` AS CHAR)) LIKE ? OR LOWER(CAST(`descr` AS CHAR)) LIKE ?)',
            $sql,
        );
    }

    public function test_an_identifier_that_tries_to_break_out_is_refused(): void
    {
        // Even reaching compile() with this requires bypassing both the mapping
        // and the discovered schema — this is the layer that would still hold.
        $this->expectException(InvalidArgumentException::class);

        $this->mysql()->compile($this->spec([
            'columns' => ['name' => 'title`; DROP TABLE users; --'],
        ]));
    }

    public function test_a_quote_character_inside_an_identifier_is_refused_by_both_drivers(): void
    {
        foreach ([$this->mysql(), $this->postgres()] as $driver) {
            try {
                $driver->compile($this->spec(['columns' => ['name' => 'ti"tl`e']]));
                $this->fail('Expected the identifier to be refused by '.$driver::class);
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_column_outside_the_mapping_cannot_be_filtered_on(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // password_hash is a real column on the shop's table — it is simply not
        // one the owner mapped, so nothing can name it.
        new ProductQuery(
            table: 'products',
            columns: ['name' => 'title'],
            conditions: [['column' => 'password_hash', 'operator' => '=', 'value' => 'x']],
        );
    }

    public function test_an_operator_outside_the_allowed_set_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ProductQuery(
            table: 'products',
            columns: ['name' => 'title'],
            conditions: [['column' => 'title', 'operator' => 'IS NOT NULL OR 1=1 --', 'value' => 'x']],
        );
    }

    public function test_a_schema_qualified_table_is_quoted_part_by_part(): void
    {
        [$sql] = $this->postgres()->compile($this->spec(['table' => 'sales.products']));

        $this->assertStringContainsString('FROM "sales"."products"', $sql);
    }

    public function test_the_row_limit_is_always_an_integer_in_the_sql(): void
    {
        [$sql] = $this->mysql()->compile($this->spec(['limit' => 20]));

        $this->assertMatchesRegularExpression('/LIMIT \d+$/', $sql);
    }

    public function test_every_compiled_statement_is_a_single_select(): void
    {
        [$sql] = $this->mysql()->compile($this->spec([
            'columns' => ['name' => 'title', 'category' => 'cat'],
            'textGroups' => [['columns' => ['cat'], 'value' => 'обувь']],
            'conditions' => [['column' => 'title', 'operator' => '=', 'value' => 'x']],
        ]));

        $this->assertSame(1, preg_match_all('/\bSELECT\b/i', $sql));
        $this->assertStringNotContainsString(';', $sql);
        $this->assertDoesNotMatchRegularExpression('/\b(INSERT|UPDATE|DELETE|DROP|ALTER|TRUNCATE)\b/i', $sql);
    }

    public function test_a_column_named_after_a_write_keyword_still_works(): void
    {
        // Shops really do have a column called "update" or "create". Quoting
        // makes it inert, and the SELECT-only check must not trip over it.
        [$sql] = $this->mysql()->compile($this->spec([
            'columns' => ['name' => 'title', 'description' => 'update'],
        ]));

        $this->assertStringContainsString('`update` AS `description`', $sql);
    }
}
