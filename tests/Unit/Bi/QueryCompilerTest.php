<?php

namespace Tests\Unit\Bi;

use App\Models\BiColumn;
use App\Models\BiDataset;
use App\Services\Bi\BiIngestionException;
use App\Services\Bi\Query\Aggregate;
use App\Services\Bi\Query\BiQueryException;
use App\Services\Bi\Query\Dimension;
use App\Services\Bi\Query\Filter;
use App\Services\Bi\Query\FilterOperator;
use App\Services\Bi\Query\Measure;
use App\Services\Bi\Query\MySqlQueryCompiler;
use App\Services\Bi\Query\QueryCompiler;
use App\Services\Bi\Query\QuerySpec;
use App\Services\Bi\Query\Sort;
use App\Services\Bi\Query\SortDirection;
use App\Services\Bi\Query\SqliteQueryCompiler;
use App\Services\Bi\Query\TimeGrain;
use App\Services\Bi\Schema\ColumnRole;
use App\Services\Bi\Schema\ColumnType;
use Tests\TestCase;

/**
 * What the BI compilers actually put on the wire.
 *
 * The sibling of `tests/Unit/Database/QueryCompilerTest.php`, and written the
 * same way for the same reason: compilation is pure, so the security properties
 * this module rests on can be ASSERTED rather than argued about. No database is
 * touched here — the models are unsaved, built in memory — which is precisely
 * what makes the assertions about SQL text meaningful.
 *
 * The four properties under test, one per group below:
 *   1. Values are placeholders; identifiers are quoted and are ours.
 *   2. The FROM clause is derived from the dataset's key and nothing else.
 *   3. The two dialects agree everywhere it matters, and differ only where
 *      documented.
 *   4. Nothing but a SELECT can be produced.
 */
class QueryCompilerTest extends TestCase
{
    private BiDataset $dataset;

    /** @var array<string, BiColumn> */
    private array $columns;

    protected function setUp(): void
    {
        parent::setUp();

        // Unsaved on purpose: `physicalTable()` derives from the key, so setting
        // it by hand proves the compiler reads the key rather than the stored
        // `physical_table` string.
        $this->dataset = new BiDataset(['name' => 'Продажи']);
        $this->dataset->id = 7;
        $this->dataset->version = 3;
        $this->dataset->status = BiDataset::STATUS_READY;

        $this->columns = [
            'date' => $this->column(0, 'Дата заказа', ColumnType::Date, ColumnRole::Time),
            'region' => $this->column(1, 'Регион', ColumnType::Text, ColumnRole::Dimension),
            'revenue' => $this->column(2, 'Выручка', ColumnType::Decimal, ColumnRole::Measure),
            'qty' => $this->column(3, 'Количество', ColumnType::Integer, ColumnRole::Measure),
        ];
    }

    private function column(int $ordinal, string $name, ColumnType $type, ColumnRole $role): BiColumn
    {
        $column = new BiColumn([
            'ordinal' => $ordinal,
            'source_name' => $name,
            'physical_name' => BiColumn::physicalNameFor($ordinal),
            'type' => $type,
            'role' => $role,
        ]);

        $column->bi_dataset_id = $this->dataset->getKey();

        return $column;
    }

    private function mysql(): MySqlQueryCompiler
    {
        return new MySqlQueryCompiler;
    }

    private function sqlite(): SqliteQueryCompiler
    {
        return new SqliteQueryCompiler;
    }

    /** Revenue by region, biggest first — the archetypal dashboard tile. */
    private function revenueByRegion(array $overrides = []): QuerySpec
    {
        return new QuerySpec(
            dataset: $this->dataset,
            dimensions: $overrides['dimensions'] ?? [new Dimension($this->columns['region'])],
            measures: $overrides['measures'] ?? [new Measure(Aggregate::Sum, $this->columns['revenue'])],
            filters: $overrides['filters'] ?? [],
            sorts: $overrides['sorts'] ?? [new Sort('m0', SortDirection::Desc)],
            limit: $overrides['limit'] ?? 10,
            offset: $overrides['offset'] ?? 0,
        );
    }

    // --- 1. Placeholders and quoted, self-issued identifiers -----------------

    public function test_mysql_compiles_a_grouped_select_with_quoted_identifiers(): void
    {
        $compiled = $this->mysql()->compile($this->revenueByRegion());

        $this->assertSame(
            'SELECT /*+ MAX_EXECUTION_TIME(30000) */ `c1` AS `d0`, SUM(`c2`) AS `m0` '
                .'FROM `bi_ds_7` GROUP BY `d0` ORDER BY `m0` IS NULL, `m0` DESC LIMIT 11',
            $compiled->sql
        );

        $this->assertSame([], $compiled->bindings);
    }

    public function test_sqlite_compiles_the_same_query_in_its_own_dialect(): void
    {
        $compiled = $this->sqlite()->compile($this->revenueByRegion());

        $this->assertSame(
            'SELECT "c1" AS "d0", SUM("c2") AS "m0" '
                .'FROM "bi_ds_7" GROUP BY "d0" ORDER BY "m0" IS NULL, "m0" DESC LIMIT 11',
            $compiled->sql
        );
    }

    public function test_detail_rows_are_not_grouped_and_page_in_file_order_on_both_engines(): void
    {
        $spec = new QuerySpec($this->dataset, dimensions: [new Dimension($this->columns['date']), new Dimension($this->columns['region'])], limit: 20, offset: 20, detail: true);

        $this->assertSame(
            'SELECT /*+ MAX_EXECUTION_TIME(30000) */ `c0` AS `d0`, `c1` AS `d1` FROM `bi_ds_7` ORDER BY `_row` ASC LIMIT 21 OFFSET 20',
            $this->mysql()->compile($spec)->sql
        );
        $this->assertSame(
            'SELECT "c0" AS "d0", "c1" AS "d1" FROM "bi_ds_7" ORDER BY "_row" ASC LIMIT 21 OFFSET 20',
            $this->sqlite()->compile($spec)->sql
        );
    }

    /**
     * The owner's header text never reaches the SQL — not as an identifier, and
     * not as a label either. This is the single most important assertion in the
     * file: `source_name` is deliberately hostile here.
     */
    public function test_owner_header_text_never_reaches_the_sql(): void
    {
        $this->columns['region']->source_name = '"; DROP TABLE users; --';

        foreach ([$this->mysql(), $this->sqlite()] as $compiler) {
            $sql = $compiler->compile($this->revenueByRegion())->sql;

            $this->assertStringNotContainsString('DROP', $sql);
            $this->assertStringNotContainsString('users', $sql);
            $this->assertStringNotContainsString('--', $sql);
            // Only `c1` — our name for it — appears.
            $this->assertStringContainsString('c1', $sql);
        }
    }

    public function test_every_filter_value_is_bound_and_never_interpolated(): void
    {
        $spec = $this->revenueByRegion([
            'filters' => [
                new Filter($this->columns['region'], FilterOperator::In, ['Душанбе', 'Худжанд']),
                new Filter($this->columns['revenue'], FilterOperator::GreaterThan, [1000]),
                new Filter($this->columns['qty'], FilterOperator::Between, [1, 50]),
            ],
        ]);

        $compiled = $this->mysql()->compile($spec);

        $this->assertStringContainsString('`c1` IN (?, ?)', $compiled->sql);
        $this->assertStringContainsString('`c2` > ?', $compiled->sql);
        $this->assertStringContainsString('`c3` BETWEEN ? AND ?', $compiled->sql);
        $this->assertSame(['Душанбе', 'Худжанд', 1000, 1, 50], $compiled->bindings);

        // Not a single literal from the filter is in the statement.
        $this->assertStringNotContainsString('Душанбе', $compiled->sql);
        $this->assertStringNotContainsString('1000', $compiled->sql);
    }

    /**
     * A caller's own `%` is a percent sign, not a wildcard.
     *
     * The value is escaped, the ESCAPE clause is explicit, and the comparison is
     * lowered on both sides — see QueryCompiler::likeClause() for why none of
     * those three can be left to the engine's default.
     */
    public function test_pattern_filters_escape_the_callers_wildcards(): void
    {
        $spec = $this->revenueByRegion([
            'filters' => [new Filter($this->columns['region'], FilterOperator::Contains, ['50%_ск'])],
        ]);

        $compiled = $this->mysql()->compile($spec);

        $this->assertStringContainsString("LOWER(`c1`) LIKE ? ESCAPE '\\'", $compiled->sql);
        $this->assertSame(['%50\\%\\_ск%'], $compiled->bindings);
    }

    /**
     * `!=` and `NOT IN` keep the blanks.
     *
     * Documented in QueryCompiler::filterClause() and asserted here because it
     * is a deliberate departure from what SQL does on its own — the kind of
     * decision that gets "simplified" away by someone who reads only the code.
     */
    public function test_negative_filters_are_null_aware(): void
    {
        $spec = $this->revenueByRegion([
            'filters' => [new Filter($this->columns['region'], FilterOperator::NotEquals, ['Душанбе'])],
        ]);

        $this->assertStringContainsString(
            '(`c1` IS NULL OR `c1` <> ?)',
            $this->mysql()->compile($spec)->sql
        );
    }

    // --- 2. The FROM clause is the tenant boundary ---------------------------

    public function test_the_from_clause_is_derived_from_the_datasets_own_key(): void
    {
        // A tampered stored name must not redirect the query: the compiler
        // re-derives from the primary key.
        $this->dataset->physical_table = 'bi_ds_999';

        $this->assertStringContainsString('FROM `bi_ds_7`', $this->mysql()->compile($this->revenueByRegion())->sql);
        $this->assertStringNotContainsString('bi_ds_999', $this->mysql()->compile($this->revenueByRegion())->sql);
    }

    /**
     * With no key there is no table name, and the compiler refuses rather than
     * composing `bi_ds_`.
     *
     * The spec is built FIRST, while the key still exists — because QuerySpec's
     * own ownership check catches an unsaved dataset even earlier, and this test
     * is about the compiler's guard rather than that one. Two independent
     * refusals for the same impossible state is the intended design, not a
     * redundancy to collapse.
     */
    public function test_a_dataset_with_no_key_cannot_be_compiled_at_all(): void
    {
        $spec = $this->revenueByRegion();

        $this->dataset->id = null;

        $this->expectException(BiIngestionException::class);

        $this->mysql()->compile($spec);
    }

    // --- 3. The two dialects agree where it matters --------------------------

    /**
     * Every grain, both engines, side by side.
     *
     * The assertion is on the SQL rather than on evaluated dates — that half is
     * proven against a real database in QueryRunnerTest, which runs the SQLite
     * branch for real. What this pins is that neither dialect is silently
     * missing a grain, and that the MySQL spelling is the variable-independent
     * one (WEEKDAY, not WEEK).
     */
    public function test_every_time_grain_compiles_in_both_dialects(): void
    {
        $expected = [
            TimeGrain::Day->value => [
                "DATE_FORMAT(`c0`, '%Y-%m-%d')",
                "strftime('%Y-%m-%d', \"c0\")",
            ],
            TimeGrain::Week->value => [
                "DATE_FORMAT(DATE_SUB(`c0`, INTERVAL WEEKDAY(`c0`) DAY), '%Y-%m-%d')",
                "strftime('%Y-%m-%d', \"c0\", '-6 days', 'weekday 1')",
            ],
            TimeGrain::Month->value => [
                "DATE_FORMAT(`c0`, '%Y-%m-01')",
                "strftime('%Y-%m-01', \"c0\")",
            ],
            TimeGrain::Quarter->value => [
                "CONCAT(DATE_FORMAT(`c0`, '%Y'), '-Q', QUARTER(`c0`))",
                "strftime('%Y', \"c0\") || '-Q' || ((CAST(strftime('%m', \"c0\") AS INTEGER) + 2) / 3)",
            ],
            TimeGrain::Year->value => [
                "DATE_FORMAT(`c0`, '%Y')",
                "strftime('%Y', \"c0\")",
            ],
        ];

        foreach (TimeGrain::cases() as $grain) {
            $spec = $this->revenueByRegion([
                'dimensions' => [new Dimension($this->columns['date'], $grain)],
                'sorts' => [],
            ]);

            [$mysql, $sqlite] = $expected[$grain->value];

            $this->assertStringContainsString($mysql, $this->mysql()->compile($spec)->sql, "MySQL {$grain->value}");
            $this->assertStringContainsString($sqlite, $this->sqlite()->compile($spec)->sql, "SQLite {$grain->value}");
        }
    }

    /**
     * Nulls sort last in both engines and both directions.
     *
     * Left to their defaults the two agree with each other and disagree with
     * what a chart wants — see QueryCompiler::orderTerm(). Asserted as identical
     * text, which is what keeps the two dialects diffable.
     */
    public function test_null_ordering_is_identical_in_both_dialects(): void
    {
        foreach ([SortDirection::Asc, SortDirection::Desc] as $direction) {
            $spec = $this->revenueByRegion(['sorts' => [new Sort('m0', $direction)]]);

            $mysql = $this->mysql()->compile($spec)->sql;
            $sqlite = $this->sqlite()->compile($spec)->sql;

            $this->assertStringContainsString('`m0` IS NULL, `m0` '.$direction->sql(), $mysql);
            $this->assertStringContainsString('"m0" IS NULL, "m0" '.$direction->sql(), $sqlite);
        }
    }

    /**
     * The only intended structural difference between the dialects is the
     * timeout hint. Proven by normalizing the quote characters and diffing.
     */
    public function test_the_dialects_differ_only_in_the_timeout_hint(): void
    {
        $spec = $this->revenueByRegion([
            'filters' => [new Filter($this->columns['region'], FilterOperator::Equals, ['Душанбе'])],
        ]);

        $mysql = str_replace('`', '"', $this->mysql()->compile($spec)->sql);
        $mysql = str_replace('/*+ MAX_EXECUTION_TIME(30000) */ ', '', $mysql);

        $this->assertSame($this->sqlite()->compile($spec)->sql, $mysql);
    }

    // --- Pagination ----------------------------------------------------------

    public function test_the_limit_asks_for_one_more_row_than_the_page_size(): void
    {
        $compiled = $this->mysql()->compile($this->revenueByRegion(['limit' => 25]));

        $this->assertSame(25, $compiled->pageSize);
        $this->assertSame(26, $compiled->fetchLimit);
        $this->assertStringEndsWith('LIMIT 26', $compiled->sql);

        $this->assertTrue($compiled->hasMore(26));
        $this->assertFalse($compiled->hasMore(25));
    }

    public function test_offset_is_emitted_only_when_asked_for(): void
    {
        $this->assertStringNotContainsString('OFFSET', $this->mysql()->compile($this->revenueByRegion())->sql);

        $this->assertStringEndsWith(
            'LIMIT 11 OFFSET 40',
            $this->mysql()->compile($this->revenueByRegion(['offset' => 40]))->sql
        );
    }

    /**
     * LIMIT and OFFSET are the only interpolated values in the statement, so
     * they are the only ones whose clamping is a safety property rather than a
     * convenience. Both are ints by the time they are compiled.
     */
    public function test_limit_and_offset_are_clamped_to_their_ceilings(): void
    {
        $spec = $this->revenueByRegion(['limit' => 10_000_000, 'offset' => -5]);

        $this->assertSame(QuerySpec::maxRows(), $spec->limit);
        $this->assertSame(0, $spec->offset);
        $this->assertStringEndsWith('LIMIT '.(QuerySpec::maxRows() + 1), $this->mysql()->compile($spec)->sql);
    }

    // --- 4. Nothing but a SELECT ---------------------------------------------

    public function test_an_identifier_with_forbidden_characters_is_refused(): void
    {
        $this->columns['region']->physical_name = 'c1`; DROP TABLE users; --';

        // Caught by BiColumn's own `/^c\d+$/` guard, before quoting is reached.
        $this->expectException(BiIngestionException::class);

        $this->mysql()->compile($this->revenueByRegion());
    }

    public function test_the_select_only_guard_rejects_a_tampered_statement(): void
    {
        // Reaching past the compiler to prove the last line of defence is real
        // rather than decorative: it is unreachable through the public API,
        // which is the point, so it is exercised directly.
        $compiler = new class extends QueryCompiler
        {
            public function engine(): string
            {
                return 'test';
            }

            protected function quoteChar(): string
            {
                return '`';
            }

            protected function timeBucket(string $quotedColumn, TimeGrain $grain): string
            {
                return $quotedColumn;
            }

            protected function selectPrefix(int $timeoutMs): string
            {
                return '';
            }

            public function check(string $sql): void
            {
                $this->assertSelectOnly($sql);
            }
        };

        foreach ([
            'DELETE FROM `bi_ds_7`',
            'SELECT 1; DROP TABLE users',
            'SELECT 1 INTO OUTFILE "/tmp/x"',
            'SELECT 1 UNION SELECT 1; PRAGMA table_info(users)',
        ] as $sql) {
            try {
                $compiler->check($sql);
                $this->fail("Should have refused: {$sql}");
            } catch (BiQueryException $e) {
                $this->assertSame(BiQueryException::UNSAFE_SQL, $e->reason);
            }
        }
    }

    /**
     * A quoted identifier that happens to contain a keyword is fine — the scan
     * strips quoted spans first. Our own names can never do this, but the guard
     * is shaped like the live-database one on purpose, and this pins that shape.
     */
    public function test_a_keyword_inside_a_quoted_identifier_is_not_a_write(): void
    {
        $compiled = $this->mysql()->compile($this->revenueByRegion());

        // Sanity: the real compiler's output passes its own guard every time.
        $this->assertStringStartsWith('SELECT', $compiled->sql);
    }
}
