<?php

namespace Tests\Feature\Bi;

use App\Jobs\Bi\DropDatasetTableJob;
use App\Models\BiColumn;
use App\Models\BiDataset;
use App\Models\User;
use App\Services\Bi\BiIngestionException;
use App\Services\Bi\Ingestion\DatasetMaterializer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\DropsAnalyticalTables;
use Tests\TestCase;

/**
 * The runtime-DDL guard.
 *
 * Materialization is the only code in this product that issues CREATE and DROP, and
 * it is the one operation here that editing a row cannot undo. So the identifiers it
 * emits are checked against a single fixed shape — `bi_ds_<digits>` for a table,
 * `c<digits>` for a column — on every use.
 *
 * These checks CANNOT fail on owner input: there is no path from a file's bytes to
 * an identifier, which the ingestion tests demonstrate by importing a header called
 * `"; DROP TABLE users; --` and finding the users table intact. What they exist for
 * is OUR bug — a future edit that lets a name through from somewhere unexpected —
 * and a guard that has never been shown to refuse anything is a guard nobody should
 * trust. Hence this file.
 */
class AnalyticalTableGuardTest extends TestCase
{
    use DropsAnalyticalTables, RefreshDatabase;

    /**
     * Every shape that is NOT a BI analytical table, including the ones that would
     * be catastrophic.
     *
     * @return array<string, array<int, string>>
     */
    public static function unsafeTableNames(): array
    {
        return [
            'an application table' => ['users'],
            'the sources table itself' => ['bi_sources'],
            'a near miss' => ['bi_ds'],
            'a non-numeric suffix' => ['bi_ds_users'],
            'a suffixed name' => ['bi_ds_1_backup'],
            'a prefixed name' => ['x_bi_ds_1'],
            'sql appended' => ['bi_ds_1; DROP TABLE users'],
            'a quoted break-out' => ['bi_ds_1`'],
            'a comment' => ['bi_ds_1 -- '],
            'a schema qualifier' => ['other.bi_ds_1'],
            'whitespace' => ['bi_ds_1 '],
            'a wildcard' => ['bi_ds_%'],
            'empty' => [''],
            'negative' => ['bi_ds_-1'],
        ];
    }

    #[DataProvider('unsafeTableNames')]
    public function test_the_table_guard_refuses_everything_that_is_not_a_bi_table(string $name): void
    {
        $this->expectException(BiIngestionException::class);

        BiDataset::assertSafeTableName($name);
    }

    public function test_the_guard_accepts_a_real_bi_table_name(): void
    {
        $this->assertSame('bi_ds_1', BiDataset::assertSafeTableName('bi_ds_1'));
        $this->assertSame('bi_ds_99123', BiDataset::assertSafeTableName('bi_ds_99123'));
    }

    public function test_the_refusal_carries_its_own_error_code(): void
    {
        try {
            BiDataset::assertSafeTableName('users');
            $this->fail('Expected a refusal.');
        } catch (BiIngestionException $e) {
            $this->assertSame(BiIngestionException::UNSAFE_TABLE_NAME, $e->reason);
        }
    }

    /**
     * The drop job is the only thing in the product that issues a DROP. It re-checks
     * the name it was handed, so a tampered or corrupted queue payload drops nothing.
     */
    public function test_the_drop_job_refuses_to_drop_anything_but_a_bi_table(): void
    {
        $this->assertTrue(Schema::hasTable('users'), 'precondition');

        (new DropDatasetTableJob('users'))->handle(app(DatasetMaterializer::class));

        $this->assertTrue(Schema::hasTable('users'), 'The drop job must refuse a name outside its one shape.');
    }

    public function test_the_drop_job_refuses_a_payload_with_sql_appended(): void
    {
        (new DropDatasetTableJob('bi_ds_1; DROP TABLE users'))->handle(app(DatasetMaterializer::class));

        $this->assertTrue(Schema::hasTable('users'));
    }

    /**
     * A shard directory outside the module's own prefix is not deleted either — the
     * job takes a path, and a path is exactly the kind of argument worth bounding.
     */
    public function test_the_drop_job_will_not_delete_a_directory_outside_the_bi_prefix(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('knowledge/7/sources/important.json', '{}');

        (new DropDatasetTableJob('bi_ds_1', 'knowledge/7'))->handle(app(DatasetMaterializer::class));

        $this->assertTrue(Storage::disk('local')->exists('knowledge/7/sources/important.json'));
    }

    public function test_the_materialiser_refuses_to_drop_a_table_outside_the_shape(): void
    {
        $this->expectException(BiIngestionException::class);

        app(DatasetMaterializer::class)->dropTable('users');
    }

    /**
     * The column-name guard, for the same reason: `c<ordinal>` is ours, and a
     * `bi_columns` row edited by hand must not become an injection vector.
     */
    public function test_the_column_guard_refuses_a_tampered_physical_name(): void
    {
        $user = User::factory()->create();
        $dataset = BiDataset::factory()->create(['user_id' => $user->id]);

        $column = BiColumn::factory()->at(0)->create([
            'bi_dataset_id' => $dataset->id,
            'user_id' => $user->id,
        ]);

        $this->assertSame('c0', $column->safePhysicalName());

        foreach (['c0; DROP TABLE users', 'users.password', '*', '', 'c', 'cx', '`c0`'] as $tampered) {
            $column->forceFill(['physical_name' => $tampered])->saveQuietly();

            try {
                $column->safePhysicalName();
                $this->fail("Expected [{$tampered}] to be refused as a column identifier.");
            } catch (BiIngestionException $e) {
                $this->assertSame(BiIngestionException::UNSAFE_TABLE_NAME, $e->reason);
            }
        }
    }

    /**
     * An unsaved dataset has no key, so `bi_ds_` is the composed name — and it must
     * fail the guard rather than becoming a table called `bi_ds_`.
     */
    public function test_a_dataset_with_no_key_cannot_produce_a_table_name(): void
    {
        $this->expectException(BiIngestionException::class);

        (new BiDataset)->physicalTable();
    }

    /**
     * Materialization refuses a dataset with no columns rather than creating a table
     * that holds only a row number.
     */
    public function test_a_dataset_with_no_columns_is_refused(): void
    {
        $user = User::factory()->create();
        $dataset = BiDataset::factory()->create(['user_id' => $user->id]);

        try {
            app(DatasetMaterializer::class)->materialize($dataset);
            $this->fail('Expected a refusal.');
        } catch (BiIngestionException $e) {
            $this->assertSame(BiIngestionException::NO_HEADER, $e->reason);
        }

        $this->assertFalse(Schema::hasTable($dataset->physicalTable()));
    }
}
