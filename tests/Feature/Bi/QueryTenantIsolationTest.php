<?php

namespace Tests\Feature\Bi;

use App\Models\BiColumn;
use App\Models\BiDataset;
use App\Models\User;
use App\Services\Bi\Ingestion\DatasetMaterializer;
use App\Services\Bi\Ingestion\SourceNormalizer;
use App\Services\Bi\Query\Aggregate;
use App\Services\Bi\Query\BiQueryException;
use App\Services\Bi\Query\Dimension;
use App\Services\Bi\Query\Filter;
use App\Services\Bi\Query\FilterOperator;
use App\Services\Bi\Query\Measure;
use App\Services\Bi\Query\MySqlQueryCompiler;
use App\Services\Bi\Query\QueryRunner;
use App\Services\Bi\Query\QuerySpec;
use App\Services\Bi\Query\SpecValidator;
use App\Services\Bi\Query\SqliteQueryCompiler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsBiFixtures;
use Tests\Support\DropsAnalyticalTables;
use Tests\TestCase;

/**
 * ONE OWNER CAN NEVER REACH ANOTHER'S NUMBERS THROUGH THE QUERY LAYER.
 *
 * `TenantIsolationTest` proves it for the HTTP surface — policies on routes,
 * uploads, the schema drawer. This file proves it for the layer underneath,
 * where the defence is structural rather than a policy check, and where a leak
 * would be silent: a chart quietly showing somebody else's revenue raises no
 * error anywhere.
 *
 * The fixture is adversarial on purpose. Both owners get a dataset with THE
 * SAME shape — same headers, so the same physical names `c0`, `c1`, `c2` — and
 * different values. Every physical identifier in one owner's query is a valid
 * identifier in the other's table, so nothing here is prevented by a name
 * simply not existing. What prevents it is:
 *
 *   1. THE FROM CLAUSE. The table is derived from the dataset's own primary
 *      key, and no spec field, filter or request parameter participates in
 *      choosing it.
 *   2. THE REGISTRY LOOKUP. A column reference is resolved against a map built
 *      from THIS dataset's `bi_columns`, so another dataset's column id is not
 *      denied — it is not found.
 *   3. THE SPEC'S OWN RE-CHECK. Even a hand-built spec holding a foreign
 *      `BiColumn` is refused, because QuerySpec compares `bi_dataset_id`.
 *   4. THE CACHE KEY. Two identical questions asked of two datasets must never
 *      share an entry — a collision there would be a leak that bypassed all
 *      three checks above.
 */
class QueryTenantIsolationTest extends TestCase
{
    use BuildsBiFixtures, DropsAnalyticalTables, RefreshDatabase;

    private User $owner;

    private User $intruder;

    private BiDataset $ownerDataset;

    private BiDataset $intruderDataset;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->owner = User::factory()->create();
        $this->intruder = User::factory()->create();

        // Identical schemas, disjoint values. Every assertion below can tell the
        // two apart by the numbers alone.
        $this->ownerDataset = $this->datasetFor($this->owner, [
            ['Дата', 'Регион', 'Выручка'],
            ['05.01.2026', 'Душанбе', '1000.00'],
            ['06.01.2026', 'Душанбе', '1000.00'],
        ]);

        $this->intruderDataset = $this->datasetFor($this->intruder, [
            ['Дата', 'Регион', 'Выручка'],
            ['05.01.2026', 'Лондон', '777777.00'],
            ['06.01.2026', 'Лондон', '777777.00'],
        ]);
    }

    private function datasetFor(User $user, array $rows): BiDataset
    {
        $source = $this->csvSource($user, $rows);
        $datasets = app(SourceNormalizer::class)->normalize($source);
        app(DatasetMaterializer::class)->materialize($datasets[0]);

        return $datasets[0]->fresh();
    }

    private function sumRevenue(BiDataset $dataset, array $extra = [])
    {
        $spec = app(SpecValidator::class)->validate($dataset, array_merge([
            'dimensions' => [['column' => 'c1']],
            'measures' => [['aggregate' => 'sum', 'column' => 'c2']],
        ], $extra));

        return app(QueryRunner::class)->run($spec);
    }

    /** Sanity: the two datasets really are the same shape with different data. */
    public function test_the_fixture_is_actually_adversarial(): void
    {
        $this->assertNotSame($this->ownerDataset->getKey(), $this->intruderDataset->getKey());

        $ownerNames = $this->ownerDataset->columns()->pluck('physical_name')->sort()->values()->all();
        $intruderNames = $this->intruderDataset->columns()->pluck('physical_name')->sort()->values()->all();

        $this->assertSame($ownerNames, $intruderNames, 'Both datasets must share physical column names.');
        $this->assertSame(['c0', 'c1', 'c2'], $ownerNames);
    }

    // --- 1. The FROM clause --------------------------------------------------

    public function test_a_query_only_ever_reads_its_own_datasets_table(): void
    {
        $this->assertSame(['Душанбе'], $this->sumRevenue($this->ownerDataset)->column('d0'));
        $this->assertSame(['Лондон'], $this->sumRevenue($this->intruderDataset)->column('d0'));
    }

    public function test_the_compiled_table_name_comes_from_the_key_not_the_stored_column(): void
    {
        // The attacker's lever, if there were one: rewrite the stored name to
        // point at the other owner's table. The compiler re-derives it.
        $this->ownerDataset->forceFill([
            'physical_table' => $this->intruderDataset->physicalTable(),
        ])->saveQuietly();

        $expected = 'bi_ds_'.$this->ownerDataset->getKey();
        $foreign = 'bi_ds_'.$this->intruderDataset->getKey();

        $spec = app(SpecValidator::class)->validate($this->ownerDataset->fresh(), [
            'measures' => [['aggregate' => 'sum', 'column' => 'c2']],
        ]);

        foreach ([new MySqlQueryCompiler, new SqliteQueryCompiler] as $compiler) {
            $sql = $compiler->compile($spec)->sql;

            $this->assertStringContainsString($expected, $sql);
            $this->assertStringNotContainsString($foreign, $sql);
        }

        $this->assertSame(2000.0, (float) app(QueryRunner::class)->run($spec)->rows[0]['m0']);
    }

    /**
     * No spec field can redirect the FROM clause.
     *
     * Dimensions, measures and filters are all tried with the other dataset's
     * table name as their payload. Each is refused as an unknown column, and
     * none of them is ever treated as an identifier.
     */
    public function test_no_spec_field_can_redirect_the_from_clause(): void
    {
        $foreign = $this->intruderDataset->physicalTable();

        $attempts = [
            ['dimensions' => [['column' => $foreign.'.c1']]],
            ['measures' => [['aggregate' => 'sum', 'column' => $foreign.'.c2']]],
            ['measures' => [['aggregate' => 'count']], 'filters' => [
                ['column' => $foreign.'.c1', 'operator' => 'eq', 'value' => 'Лондон'],
            ]],
            ['dimensions' => [['column' => 'c1']], 'measures' => [
                ['aggregate' => 'sum', 'column' => 'c2 FROM '.$foreign.' --'],
            ]],
        ];

        foreach ($attempts as $i => $attempt) {
            try {
                app(SpecValidator::class)->validate($this->ownerDataset, $attempt);
                $this->fail("Attempt {$i} should have been refused.");
            } catch (BiQueryException $e) {
                $this->assertSame(BiQueryException::UNKNOWN_COLUMN, $e->reason);
            }
        }
    }

    // --- 2. The registry lookup is scoped to the dataset ---------------------

    /**
     * Another owner's column ID is not found, not denied.
     *
     * The distinction matters: "denied" implies a check that could be forgotten
     * somewhere else. The map simply does not contain the row.
     */
    public function test_another_owners_column_id_is_not_found(): void
    {
        $foreignId = $this->intruderDataset->columns()->where('physical_name', 'c2')->value('id');

        $this->assertNotNull($foreignId);

        try {
            app(SpecValidator::class)->validate($this->ownerDataset, [
                'measures' => [['aggregate' => 'sum', 'column' => $foreignId]],
            ]);
            $this->fail('A foreign column id must not resolve.');
        } catch (BiQueryException $e) {
            $this->assertSame(BiQueryException::UNKNOWN_COLUMN, $e->reason);
        }
    }

    /**
     * The SAME physical name resolves to a different row per dataset.
     *
     * `c2` exists in both tables; each owner's query must bind to their own
     * `bi_columns` row, which is what keeps the registry a tenant boundary
     * rather than a global name table.
     */
    public function test_the_same_physical_name_resolves_per_dataset(): void
    {
        $ownerSpec = app(SpecValidator::class)->validate($this->ownerDataset, [
            'measures' => [['aggregate' => 'sum', 'column' => 'c2']],
        ]);

        $intruderSpec = app(SpecValidator::class)->validate($this->intruderDataset, [
            'measures' => [['aggregate' => 'sum', 'column' => 'c2']],
        ]);

        $this->assertNotSame(
            $ownerSpec->measures[0]->column->getKey(),
            $intruderSpec->measures[0]->column->getKey()
        );

        $this->assertSame(2000.0, (float) app(QueryRunner::class)->run($ownerSpec)->rows[0]['m0']);
        $this->assertSame(1555554.0, (float) app(QueryRunner::class)->run($intruderSpec)->rows[0]['m0']);
    }

    // --- 3. The spec re-checks even a hand-built query ------------------------

    /**
     * Bypassing the validator entirely still fails.
     *
     * This is the case the validator cannot cover, and the reason QuerySpec
     * re-checks rather than trusting its caller: a dashboard reading a spec back
     * from storage, or the AI analyst composing one, does not go through
     * SpecValidator. The guarantee has to be "a QuerySpec that exists is safe".
     */
    public function test_a_hand_built_spec_holding_a_foreign_column_is_refused(): void
    {
        $foreignColumn = $this->intruderDataset->columns()->where('physical_name', 'c2')->first();

        $cases = [
            'measure' => fn () => new QuerySpec(
                dataset: $this->ownerDataset,
                measures: [new Measure(Aggregate::Sum, $foreignColumn)],
            ),
            'dimension' => fn () => new QuerySpec(
                dataset: $this->ownerDataset,
                dimensions: [new Dimension($foreignColumn)],
            ),
            'filter' => fn () => new QuerySpec(
                dataset: $this->ownerDataset,
                measures: [new Measure(Aggregate::Count)],
                filters: [new Filter($foreignColumn, FilterOperator::GreaterThan, [0])],
            ),
        ];

        foreach ($cases as $role => $build) {
            try {
                $build();
                $this->fail("A foreign column as a {$role} must be refused.");
            } catch (BiQueryException $e) {
                $this->assertSame(BiQueryException::UNKNOWN_COLUMN, $e->reason, $role);
            }
        }
    }

    /**
     * Same owner, different dataset, is ALSO refused.
     *
     * Belonging to the same account is not enough: the compiled query reads one
     * physical table, and a sibling dataset's column would name an identifier
     * that table does not have. Catching it here makes the failure a classified
     * refusal rather than an SQL error from the server.
     */
    public function test_a_column_from_a_sibling_dataset_of_the_same_owner_is_refused(): void
    {
        $sibling = $this->datasetFor($this->owner, [
            ['Товар', 'Цена'],
            ['Куртка', '500'],
        ]);

        $siblingColumn = $sibling->columns()->where('physical_name', 'c1')->first();

        $this->expectException(BiQueryException::class);

        new QuerySpec(
            dataset: $this->ownerDataset,
            measures: [new Measure(Aggregate::Sum, $siblingColumn)],
        );
    }

    // --- 4. The cache cannot collide across datasets -------------------------

    /**
     * THE QUIETEST POSSIBLE LEAK, and the reason this test exists.
     *
     * Two owners asking the identical question of identically-shaped datasets
     * produce identical specs in every respect except the dataset. If the cache
     * key were built from the question alone, the second asker would be served
     * the first one's numbers — past the FROM clause, past the registry, past
     * the policy, with no error anywhere and no query even reaching the
     * database. So the key carries the dataset id twice over: in the namespace,
     * and inside the fingerprint.
     */
    public function test_identical_questions_on_two_datasets_never_share_a_cache_entry(): void
    {
        $ownerResult = $this->sumRevenue($this->ownerDataset);
        $intruderResult = $this->sumRevenue($this->intruderDataset);

        $this->assertFalse($ownerResult->cached);
        $this->assertFalse($intruderResult->cached, 'The second owner must not hit the first one\'s entry.');

        $this->assertSame(['Душанбе'], $ownerResult->column('d0'));
        $this->assertSame(['Лондон'], $intruderResult->column('d0'));

        // And the fingerprints themselves differ, not merely the namespace.
        $ownerSpec = app(SpecValidator::class)->validate($this->ownerDataset, [
            'measures' => [['aggregate' => 'sum', 'column' => 'c2']],
        ]);
        $intruderSpec = app(SpecValidator::class)->validate($this->intruderDataset, [
            'measures' => [['aggregate' => 'sum', 'column' => 'c2']],
        ]);

        $this->assertNotSame($ownerSpec->fingerprint(), $intruderSpec->fingerprint());
    }

    /** A warm cache on one dataset never answers for the other. */
    public function test_a_warm_cache_does_not_answer_across_tenants(): void
    {
        $this->sumRevenue($this->ownerDataset);
        $this->assertTrue($this->sumRevenue($this->ownerDataset)->cached);

        $intruderResult = $this->sumRevenue($this->intruderDataset);

        $this->assertFalse($intruderResult->cached);
        $this->assertSame(1555554.0, (float) $intruderResult->rows[0]['m0']);
    }

    // --- The policy still guards the models themselves -----------------------

    public function test_the_dataset_policy_still_denies_the_intruder(): void
    {
        $this->assertFalse($this->intruder->can('view', $this->ownerDataset));
        $this->assertTrue($this->owner->can('view', $this->ownerDataset));

        $foreignColumn = $this->ownerDataset->columns()->first();

        $this->assertFalse($this->intruder->can('view', $foreignColumn));
        $this->assertTrue($this->owner->can('view', $foreignColumn));
    }

    /**
     * `scopeOwnedBy` is where a controller's dataset lookup starts, so an
     * intruder never holds the model the query layer would need.
     */
    public function test_owned_by_scope_hides_the_other_accounts_datasets(): void
    {
        $visible = BiDataset::ownedBy($this->intruder)->pluck('id')->all();

        $this->assertContains($this->intruderDataset->getKey(), $visible);
        $this->assertNotContains($this->ownerDataset->getKey(), $visible);
    }
}
