<?php

namespace Tests\Feature\Bi;

use App\Models\BiDataset;
use App\Models\User;
use App\Services\Bi\Ingestion\DatasetMaterializer;
use App\Services\Bi\Ingestion\SourceNormalizer;
use App\Services\Bi\Query\BiQueryException;
use App\Services\Bi\Query\QueryRunner;
use App\Services\Bi\Query\SpecValidator;
use App\Services\Bi\Query\TimeGrain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsBiFixtures;
use Tests\Support\DropsAnalyticalTables;
use Tests\TestCase;

/**
 * The query layer end to end, against a real materialized table.
 *
 * QueryCompilerTest proves what the SQL SAYS; this proves what it DOES. The
 * dataset here is built by the real ingest pipeline — normalize, infer,
 * materialize — so the table these queries read has the types `SchemaInferrer`
 * chose and the nulls the owner's blanks became, rather than a fixture shaped to
 * make the assertions pass.
 *
 * It runs on SQLite, which is what `phpunit.xml` pins the suite to. That makes
 * SqliteQueryCompiler the one under test here, and the MySQL dialect is covered
 * by string assertions next door. The pairing is deliberate: the dialects are
 * asserted to be identical modulo the timeout hint, so exercising one for real
 * and both on paper is what the suite can honestly claim.
 */
class QueryRunnerTest extends TestCase
{
    use BuildsBiFixtures, DropsAnalyticalTables, RefreshDatabase;

    private User $owner;

    private BiDataset $dataset;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->owner = User::factory()->create();
        $this->dataset = $this->salesDataset();
    }

    /**
     * Three regions, four months, one blank revenue and one blank region.
     *
     * The blanks are the point of the fixture rather than noise: every
     * materialized column is nullable, so null handling is the normal case here,
     * and a fixture without them would not exercise the NULL-aware filters or
     * the nulls-last ordering.
     */
    private function salesDataset(): BiDataset
    {
        $source = $this->csvSource($this->owner, [
            ['Дата', 'Регион', 'Выручка', 'Количество'],
            ['05.01.2026', 'Душанбе', '1000.00', '2'],
            ['12.01.2026', 'Душанбе', '500.00', '1'],
            ['03.02.2026', 'Худжанд', '2000.00', '4'],
            ['14.02.2026', 'Худжанд', '', '3'],
            ['09.03.2026', 'Куляб', '750.50', '1'],
            ['23.03.2026', '', '250.00', '5'],
        ]);

        $datasets = app(SourceNormalizer::class)->normalize($source);
        app(DatasetMaterializer::class)->materialize($datasets[0]);

        return $datasets[0]->fresh();
    }

    private function ask(array $input, bool $fresh = false)
    {
        $spec = app(SpecValidator::class)->validate($this->dataset, $input);

        return app(QueryRunner::class)->run($spec, $fresh);
    }

    // --- Grouping and aggregation -------------------------------------------

    public function test_revenue_by_region_sums_correctly_and_sorts_descending(): void
    {
        $result = $this->ask([
            'dimensions' => [['column' => 'c1']],
            'measures' => [['aggregate' => 'sum', 'column' => 'c2']],
            'sorts' => [['field' => 'm0', 'direction' => 'desc']],
        ]);

        $this->assertSame(
            ['Худжанд', 'Душанбе', 'Куляб', null],
            $result->column('d0'),
            'The blank region must sort last, not first.'
        );

        $this->assertEqualsWithDelta(2000.0, (float) $result->rows[0]['m0'], 0.001);
        $this->assertEqualsWithDelta(1500.0, (float) $result->rows[1]['m0'], 0.001);
        $this->assertEqualsWithDelta(750.5, (float) $result->rows[2]['m0'], 0.001);
    }

    /**
     * COUNT counts ROWS, including the one whose revenue is blank.
     *
     * The distinction `Aggregate::needsColumn()` exists for: `COUNT(c2)` would
     * say three for Худжанд's two rows... which is to say it would say one, and
     * "how many orders" would quietly depend on which column was picked.
     */
    public function test_count_counts_rows_rather_than_non_null_values(): void
    {
        $result = $this->ask([
            'dimensions' => [['column' => 'c1']],
            'measures' => [['aggregate' => 'count'], ['aggregate' => 'sum', 'column' => 'c2']],
            'sorts' => [['field' => 'd0', 'direction' => 'asc']],
        ]);

        $byRegion = [];
        foreach ($result->rows as $row) {
            $byRegion[$row['d0'] ?? ''] = (int) $row['m0'];
        }

        $this->assertSame(2, $byRegion['Худжанд'], 'The row with a blank revenue still counts.');
        $this->assertSame(2, $byRegion['Душанбе']);
        $this->assertSame(1, $byRegion['Куляб']);
    }

    public function test_a_scalar_query_returns_one_row_and_no_group_by(): void
    {
        $result = $this->ask(['measures' => [['aggregate' => 'count']]]);

        $this->assertCount(1, $result->rows);
        $this->assertSame(6, (int) $result->rows[0]['m0']);
    }

    public function test_count_distinct_ignores_repeats(): void
    {
        $result = $this->ask(['measures' => [['aggregate' => 'count_distinct', 'column' => 'c1']]]);

        // Душанбе, Худжанд, Куляб — the blank is not a distinct value.
        $this->assertSame(3, (int) $result->rows[0]['m0']);
    }

    public function test_avg_is_decimal_rather_than_truncated(): void
    {
        $result = $this->ask([
            'measures' => [['aggregate' => 'avg', 'column' => 'c3']],
        ]);

        // (2+1+4+3+1+5)/6 = 2.666…, not 2.
        $this->assertEqualsWithDelta(2.6667, (float) $result->rows[0]['m0'], 0.001);
    }

    // --- Time grains, evaluated for real ------------------------------------

    /**
     * Every grain, against real dates, producing exactly the strings TimeGrain
     * documents.
     *
     * This is the half QueryCompilerTest cannot prove: that the SQLite spelling
     * actually evaluates to the shape the MySQL spelling is written to produce.
     */
    public function test_every_grain_buckets_real_dates_into_the_documented_shape(): void
    {
        $expected = [
            // 05.01.2026 is a Monday; 12.01.2026 the next Monday.
            TimeGrain::Day->value => ['2026-01-05', '2026-01-12', '2026-02-03', '2026-02-14', '2026-03-09', '2026-03-23'],
            TimeGrain::Week->value => ['2026-01-05', '2026-01-12', '2026-02-02', '2026-02-09', '2026-03-09', '2026-03-23'],
            TimeGrain::Month->value => ['2026-01-01', '2026-02-01', '2026-03-01'],
            TimeGrain::Quarter->value => ['2026-Q1'],
            TimeGrain::Year->value => ['2026'],
        ];

        foreach ($expected as $grain => $buckets) {
            $result = $this->ask([
                'dimensions' => [['column' => 'c0', 'grain' => $grain]],
                'measures' => [['aggregate' => 'count']],
                'sorts' => [['field' => 'd0', 'direction' => 'asc']],
            ]);

            $this->assertSame($buckets, $result->column('d0'), "Grain [{$grain}] bucketed wrongly.");
        }
    }

    public function test_a_quarter_bucket_is_not_date_shaped_and_says_so(): void
    {
        $this->assertFalse(TimeGrain::Quarter->isDateShaped());
        $this->assertTrue(TimeGrain::Month->isDateShaped());
    }

    // --- Filters -------------------------------------------------------------

    public function test_filters_bind_their_values_and_narrow_the_result(): void
    {
        $result = $this->ask([
            'measures' => [['aggregate' => 'count']],
            'filters' => [['column' => 'c2', 'operator' => 'gte', 'value' => 1000]],
        ]);

        $this->assertSame(2, (int) $result->rows[0]['m0']);
    }

    public function test_in_filters_accept_a_list(): void
    {
        $result = $this->ask([
            'measures' => [['aggregate' => 'count']],
            'filters' => [['column' => 'c1', 'operator' => 'in', 'values' => ['Душанбе', 'Куляб']]],
        ]);

        $this->assertSame(3, (int) $result->rows[0]['m0']);
    }

    /**
     * `!=` keeps the blanks, as QueryCompiler::filterClause() documents.
     *
     * Six rows, two of them Душанбе, one of them blank — a plain SQL `<>` would
     * answer three and silently lose the blank.
     */
    public function test_not_equals_keeps_the_rows_whose_value_is_blank(): void
    {
        $result = $this->ask([
            'measures' => [['aggregate' => 'count']],
            'filters' => [['column' => 'c1', 'operator' => 'ne', 'value' => 'Душанбе']],
        ]);

        $this->assertSame(4, (int) $result->rows[0]['m0']);
    }

    public function test_is_blank_finds_the_empty_cells(): void
    {
        $blankRevenue = $this->ask([
            'measures' => [['aggregate' => 'count']],
            'filters' => [['column' => 'c2', 'operator' => 'is_blank']],
        ]);

        $this->assertSame(1, (int) $blankRevenue->rows[0]['m0']);

        $blankRegion = $this->ask([
            'measures' => [['aggregate' => 'count']],
            'filters' => [['column' => 'c1', 'operator' => 'is_blank']],
        ]);

        $this->assertSame(1, (int) $blankRegion->rows[0]['m0']);
    }

    /**
     * A caller's own `%` is a literal percent sign.
     *
     * ASCII-only, deliberately: SQLite's LOWER() does not fold Cyrillic, which
     * QueryCompiler::likeClause() documents as an accepted local-only
     * difference. Asserting it with ASCII keeps this test honest about what it
     * actually proves on this engine.
     */
    public function test_contains_escapes_the_callers_wildcards(): void
    {
        $source = $this->csvSource($this->owner, [
            ['Код', 'Выручка'],
            ['SALE-50%', '100'],
            ['SALE-5012', '200'],
        ]);
        $datasets = app(SourceNormalizer::class)->normalize($source);
        app(DatasetMaterializer::class)->materialize($datasets[0]);

        $spec = app(SpecValidator::class)->validate($datasets[0]->fresh(), [
            'dimensions' => [['column' => 'c0']],
            'measures' => [['aggregate' => 'count']],
            'filters' => [['column' => 'c0', 'operator' => 'contains', 'value' => '50%']],
        ]);

        $result = app(QueryRunner::class)->run($spec);

        $this->assertSame(['SALE-50%'], $result->column('d0'), 'The % must match literally, not as a wildcard.');
    }

    // --- Pagination ----------------------------------------------------------

    public function test_pagination_reports_another_page_without_a_second_count(): void
    {
        $page1 = $this->ask([
            'dimensions' => [['column' => 'c0', 'grain' => 'day']],
            'measures' => [['aggregate' => 'count']],
            'sorts' => [['field' => 'd0', 'direction' => 'asc']],
            'limit' => 4,
        ]);

        $this->assertCount(4, $page1->rows, 'The probe row must never reach the caller.');
        $this->assertTrue($page1->hasMore);
        $this->assertSame(0, $page1->offset);

        $page2 = $this->ask([
            'dimensions' => [['column' => 'c0', 'grain' => 'day']],
            'measures' => [['aggregate' => 'count']],
            'sorts' => [['field' => 'd0', 'direction' => 'asc']],
            'limit' => 4,
            'offset' => 4,
        ]);

        $this->assertCount(2, $page2->rows);
        $this->assertFalse($page2->hasMore);
        $this->assertSame(['2026-03-09', '2026-03-23'], $page2->column('d0'));
    }

    // --- Cache ---------------------------------------------------------------

    public function test_an_identical_query_is_served_from_cache(): void
    {
        $spec = ['dimensions' => [['column' => 'c1']], 'measures' => [['aggregate' => 'count']]];

        $first = $this->ask($spec);
        $second = $this->ask($spec);

        $this->assertFalse($first->cached);
        $this->assertTrue($second->cached);
        $this->assertSame($first->rows, $second->rows);

        // The original cost survives the round trip — see QueryResult::fromCache().
        $this->assertSame($first->durationMs, $second->durationMs);
    }

    public function test_a_fresh_run_bypasses_the_cache_but_still_refills_it(): void
    {
        $spec = ['dimensions' => [['column' => 'c1']], 'measures' => [['aggregate' => 'count']]];

        $this->ask($spec);

        $this->assertFalse($this->ask($spec, fresh: true)->cached);
        $this->assertTrue($this->ask($spec)->cached);
    }

    /**
     * Filters in a different order are the same question, and share one entry.
     *
     * Sorts are NOT reordered — their order is their meaning — and the second
     * half of this test pins that asymmetry.
     */
    public function test_the_cache_key_is_order_insensitive_for_filters_only(): void
    {
        $a = ['column' => 'c1', 'operator' => 'ne', 'value' => 'Куляб'];
        $b = ['column' => 'c2', 'operator' => 'gte', 'value' => 100];

        $this->ask(['measures' => [['aggregate' => 'count']], 'filters' => [$a, $b]]);

        $this->assertTrue(
            $this->ask(['measures' => [['aggregate' => 'count']], 'filters' => [$b, $a]])->cached,
            'Reordered filters should hit the same cache entry.'
        );

        $base = [
            'dimensions' => [['column' => 'c1']],
            'measures' => [['aggregate' => 'count'], ['aggregate' => 'sum', 'column' => 'c2']],
        ];

        $this->ask($base + ['sorts' => [['field' => 'm0'], ['field' => 'm1']]]);

        $this->assertFalse(
            $this->ask($base + ['sorts' => [['field' => 'm1'], ['field' => 'm0']]])->cached,
            'Reordered sorts are a different query and must not share a cache entry.'
        );
    }

    /**
     * Rematerializing invalidates every cached tile over the dataset, without
     * anything having to go and find them.
     *
     * The dataset's `version` is part of the fingerprint, so the new version
     * simply produces different keys and the old entries expire on their own
     * TTL. This is the behaviour the schema editor's "fix a wrong type" flow
     * depends on — see QuerySpec::fingerprint().
     */
    public function test_rematerializing_invalidates_cached_results(): void
    {
        $spec = ['dimensions' => [['column' => 'c1']], 'measures' => [['aggregate' => 'count']]];

        $this->ask($spec);
        $this->assertTrue($this->ask($spec)->cached);

        app(DatasetMaterializer::class)->materialize($this->dataset->fresh());
        $this->dataset = $this->dataset->fresh();

        $this->assertFalse($this->ask($spec)->cached, 'A rebuilt dataset must not serve stale numbers.');
    }

    public function test_renaming_a_header_does_not_invalidate_the_cache(): void
    {
        $spec = ['dimensions' => [['column' => 'c1']], 'measures' => [['aggregate' => 'count']]];

        $this->ask($spec);

        $this->dataset->columns()->where('physical_name', 'c1')->update(['label' => 'Область']);
        $this->dataset = $this->dataset->fresh();

        // The numbers did not change, so the cached answer is still correct —
        // the fingerprint uses column IDS, not names, precisely so that renaming
        // a header is free. The new label still reaches the caller, because the
        // column description is rebuilt from the spec rather than cached blindly.
        $result = $this->ask($spec);

        $this->assertTrue($result->cached);
    }

    // --- Refusals about the world -------------------------------------------

    public function test_a_dataset_that_is_not_ready_is_refused(): void
    {
        $this->dataset->update(['status' => BiDataset::STATUS_MATERIALIZING]);

        try {
            $this->ask(['measures' => [['aggregate' => 'count']]]);
            $this->fail('Expected a refusal.');
        } catch (BiQueryException $e) {
            $this->assertSame(BiQueryException::NOT_READY, $e->reason);
            $this->assertFalse($e->isTransient());
        }
    }

    /**
     * A dataset whose table is being rebuilt refuses with a TRANSIENT code.
     *
     * The distinction is the point: the caller should retry this one, and the UI
     * should say "a moment" rather than "something is wrong".
     */
    public function test_a_missing_table_is_a_transient_refusal(): void
    {
        Schema::drop($this->dataset->physicalTable());

        try {
            $this->ask(['measures' => [['aggregate' => 'count']]]);
            $this->fail('Expected a refusal.');
        } catch (BiQueryException $e) {
            $this->assertSame(BiQueryException::TABLE_MISSING, $e->reason);
            $this->assertTrue($e->isTransient());
        }
    }

    public function test_a_live_connection_dataset_is_refused_by_this_compiler(): void
    {
        $this->dataset->update(['external_table' => 'orders']);

        try {
            $this->ask(['measures' => [['aggregate' => 'count']]]);
            $this->fail('Expected a refusal.');
        } catch (BiQueryException $e) {
            $this->assertSame(BiQueryException::EXTERNAL_UNSUPPORTED, $e->reason);
        }
    }

    // --- Result metadata -----------------------------------------------------

    public function test_the_result_describes_its_columns_for_the_chart(): void
    {
        $result = $this->ask([
            'dimensions' => [['column' => 'c0', 'grain' => 'month']],
            'measures' => [['aggregate' => 'avg', 'column' => 'c2']],
        ]);

        $this->assertSame([
            ['alias' => 'd0', 'kind' => 'dimension', 'label' => 'Дата', 'type' => 'text', 'grain' => 'month', 'aggregate' => null],
            ['alias' => 'm0', 'kind' => 'measure', 'label' => 'Выручка', 'type' => 'decimal', 'grain' => null, 'aggregate' => 'avg'],
        ], $result->columns);
    }

    /**
     * What goes into the cache is plain data, not a serialized object.
     *
     * Asserted by reading the entry back through the cache repository directly:
     * an object here would be a landmine across a deploy, because
     * `unserialize()` of a class whose constructor has since changed is a fatal
     * error on a cache HIT — the one place where clearing the cache is the only
     * cure and nothing says so. See QueryResult::toCache().
     */
    public function test_what_is_cached_is_plain_data(): void
    {
        $spec = app(SpecValidator::class)->validate($this->dataset, [
            'dimensions' => [['column' => 'c1']],
            'measures' => [['aggregate' => 'count']],
        ]);

        app(QueryRunner::class)->run($spec);

        $entry = Cache::get('bi:q:'.$this->dataset->getKey().':'.$spec->fingerprint());

        $this->assertIsArray($entry);
        $this->assertSame(
            ['rows', 'columns', 'has_more', 'offset', 'page_size', 'duration_ms'],
            array_keys($entry)
        );

        foreach ($entry['rows'] as $row) {
            $this->assertIsArray($row);
        }
    }
}
