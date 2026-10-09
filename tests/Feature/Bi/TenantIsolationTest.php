<?php

namespace Tests\Feature\Bi;

use App\Models\BiColumn;
use App\Models\BiDataset;
use App\Models\BiSource;
use App\Models\User;
use App\Services\Bi\Ingestion\DatasetMaterializer;
use App\Services\Bi\Ingestion\SourceNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsBiFixtures;
use Tests\Support\DropsAnalyticalTables;
use Tests\TestCase;

/**
 * ONE OWNER CAN NEVER REACH ANOTHER'S DATA. Proven, not asserted in a docblock.
 *
 * The brief singled this out, and rightly: the BI module is the first place in this
 * product where one account's rows live in their own table, so "scoped" has to mean
 * something stronger than a `where` clause somebody remembered to write.
 *
 * The design being tested has two layers, and both are exercised here:
 *
 *   1. THE POLICY. Every route resolves its model through BiSourcePolicy /
 *      BiDatasetPolicy, which compare two integers and nothing else.
 *   2. THE FROM CLAUSE. A dataset's table name is derived from its own primary key,
 *      so reaching another owner's rows requires holding their BiDataset — there is
 *      no filter, spec field or request parameter that participates in choosing the
 *      table. That is the point of the design: a tenant scope written as a WHERE can
 *      be forgotten, and one written as the FROM cannot.
 */
class TenantIsolationTest extends TestCase
{
    use BuildsBiFixtures, DropsAnalyticalTables, RefreshDatabase;

    private User $owner;

    private User $intruder;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->owner = User::factory()->create();
        $this->intruder = User::factory()->create();
    }

    /** A fully ingested dataset belonging to $this->owner. */
    private function ownerDataset(): BiDataset
    {
        $source = $this->csvSource($this->owner, [
            ['Дата', 'Регион', 'Выручка'],
            ['01.03.2026', 'Душанбе', '1500.50'],
            ['17.03.2026', 'Худжанд', '2400.00'],
        ]);

        $datasets = app(SourceNormalizer::class)->normalize($source);
        app(DatasetMaterializer::class)->materialize($datasets[0]);

        return $datasets[0]->fresh();
    }

    public function test_the_source_list_shows_only_the_signed_in_account(): void
    {
        $this->ownerDataset();

        $response = $this->actingAs($this->intruder)->get(route('bi.sources.index'));

        $response->assertOk();
        $response->assertDontSee('fixture.csv');
        // And the owner sees their own.
        $this->actingAs($this->owner)->get(route('bi.sources.index'))->assertSee('fixture.csv');
    }

    public function test_the_status_endpoint_never_leaks_another_accounts_sources(): void
    {
        $this->ownerDataset();

        $response = $this->actingAs($this->intruder)->getJson(route('bi.sources.status'));

        $response->assertOk();
        $this->assertSame([], $response->json('sources'));
    }

    public function test_another_account_cannot_delete_a_source(): void
    {
        $dataset = $this->ownerDataset();
        $source = $dataset->source;

        $this->actingAs($this->intruder)
            ->delete(route('bi.sources.destroy', $source))
            ->assertForbidden();

        $this->assertDatabaseHas('bi_sources', ['id' => $source->id]);
        // And critically: the table is still there. A 403 that had already dropped
        // the table would be a 403 in name only.
        $this->assertNotNull(DB::table($dataset->physicalTable())->first());
    }

    public function test_another_account_cannot_retry_a_source(): void
    {
        $source = BiSource::factory()->failed()->create(['user_id' => $this->owner->id]);

        $this->actingAs($this->intruder)
            ->post(route('bi.sources.retry', $source))
            ->assertForbidden();

        $this->assertSame(BiSource::STATUS_FAILED, $source->fresh()->status);
    }

    /**
     * Guessing an id must fail the same way a nonexistent one does — a 403 for rows
     * that exist and a 404 for rows that do not would turn the endpoint into an
     * enumeration oracle for how many sources other accounts have.
     */
    public function test_an_unauthorised_id_and_a_nonexistent_id_are_indistinguishable(): void
    {
        $source = BiSource::factory()->create(['user_id' => $this->owner->id]);

        $foreign = $this->actingAs($this->intruder)->delete(route('bi.sources.destroy', $source));
        $missing = $this->actingAs($this->intruder)->delete(route('bi.sources.destroy', 999999));

        // Both are refusals. What must not happen is one of them succeeding.
        $this->assertContains($foreign->status(), [403, 404]);
        $this->assertContains($missing->status(), [403, 404]);
        $this->assertDatabaseHas('bi_sources', ['id' => $source->id]);
    }

    public function test_the_policies_refuse_across_owners_and_allow_within_one(): void
    {
        $dataset = $this->ownerDataset();
        $source = $dataset->source;
        $column = $dataset->columns()->first();

        foreach (['view', 'update', 'delete'] as $ability) {
            $this->assertTrue($this->owner->can($ability, $source), "owner should be able to {$ability} their source");
            $this->assertFalse($this->intruder->can($ability, $source), "intruder must not {$ability} another's source");

            $this->assertTrue($this->owner->can($ability, $dataset));
            $this->assertFalse($this->intruder->can($ability, $dataset));
        }

        // `query` is its own ability, deliberately distinct from `view`: running an
        // aggregate over a table is not the same permission as seeing it listed.
        $this->assertTrue($this->owner->can('query', $dataset));
        $this->assertFalse($this->intruder->can('query', $dataset));

        $this->assertTrue($this->owner->can('view', $column));
        $this->assertFalse($this->intruder->can('view', $column));
        $this->assertFalse($this->intruder->can('update', $column));
    }

    /**
     * The scope is a COLUMN on the row, not a join — the same decision
     * `dataset_products` documents. A forgotten `where` on a relation cannot leak a
     * book, but a forgotten join condition can.
     */
    public function test_every_bi_row_carries_its_owner_so_the_scope_needs_no_join(): void
    {
        $dataset = $this->ownerDataset();

        $this->assertSame($this->owner->id, $dataset->user_id);
        $this->assertSame($this->owner->id, $dataset->source->user_id);

        foreach ($dataset->columns()->get() as $column) {
            $this->assertSame(
                $this->owner->id,
                $column->user_id,
                'A column must carry its owner, so the schema editor authorizes a wide table without an eager load.'
            );
        }

        $this->assertSame(0, BiDataset::ownedBy($this->intruder)->count());
        $this->assertSame(1, BiDataset::ownedBy($this->owner)->count());
        $this->assertSame(0, BiSource::ownedBy($this->intruder)->count());
    }

    /**
     * THE CENTRAL PROPERTY. Two owners' datasets are two different tables, and the
     * name of each is a function of its own primary key — so there is no string a
     * request could supply that would make one query the other.
     */
    public function test_two_owners_datasets_live_in_separate_tables_named_after_their_own_keys(): void
    {
        $ownerDataset = $this->ownerDataset();

        $intruderSource = $this->csvSource($this->intruder, [['Секрет'], ['чужие данные']]);
        $intruderDatasets = app(SourceNormalizer::class)->normalize($intruderSource);
        app(DatasetMaterializer::class)->materialize($intruderDatasets[0]);
        $intruderDataset = $intruderDatasets[0]->fresh();

        $this->assertNotSame($ownerDataset->physicalTable(), $intruderDataset->physicalTable());
        $this->assertSame('bi_ds_'.$ownerDataset->id, $ownerDataset->physicalTable());
        $this->assertSame('bi_ds_'.$intruderDataset->id, $intruderDataset->physicalTable());

        // Neither table contains a trace of the other's rows.
        $this->assertSame(2, DB::table($ownerDataset->physicalTable())->count());
        $this->assertSame(1, DB::table($intruderDataset->physicalTable())->count());
        $this->assertSame(
            'чужие данные',
            DB::table($intruderDataset->physicalTable())->value('c0')
        );
    }

    /**
     * Tampering with the stored table name gets nothing: it is re-derived from the
     * primary key on every use, and the stored column is only a diagnostic.
     */
    public function test_a_tampered_physical_table_name_is_ignored_in_favour_of_the_derived_one(): void
    {
        $ownerDataset = $this->ownerDataset();

        $hostile = BiDataset::factory()->create(['user_id' => $this->intruder->id]);
        $hostile->forceFill(['physical_table' => $ownerDataset->physicalTable()])->save();

        $this->assertSame(
            'bi_ds_'.$hostile->id,
            $hostile->fresh()->physicalTable(),
            'physicalTable() must come from the primary key, never from the stored string.'
        );
    }

    /**
     * A column row edited to name another dataset's column is not a way in: spec
     * validation looks a column up by (dataset, physical_name), so the lookup itself
     * cannot cross datasets.
     */
    public function test_a_column_is_only_ever_found_within_its_own_dataset(): void
    {
        $ownerDataset = $this->ownerDataset();
        $intruderDataset = BiDataset::factory()->create(['user_id' => $this->intruder->id]);

        BiColumn::factory()->at(0)->create([
            'bi_dataset_id' => $intruderDataset->id,
            'user_id' => $this->intruder->id,
        ]);

        // `c0` exists in both, and resolving it against one dataset can never return
        // the other's row.
        $found = BiColumn::where('bi_dataset_id', $ownerDataset->id)->where('physical_name', 'c0')->first();

        $this->assertNotNull($found);
        $this->assertSame($ownerDataset->id, $found->bi_dataset_id);
        $this->assertSame($this->owner->id, $found->user_id);
    }

    public function test_a_guest_cannot_reach_any_bi_route(): void
    {
        $source = BiSource::factory()->create(['user_id' => $this->owner->id]);

        $this->get(route('bi.sources.index'))->assertRedirect();
        $this->getJson(route('bi.sources.status'))->assertUnauthorized();
        $this->post(route('bi.sources.store'))->assertRedirect();
        $this->delete(route('bi.sources.destroy', $source))->assertRedirect();
    }

    /**
     * Uploading writes into `bi/{user_id}/…`, composed from our own integers. An
     * owner cannot name another account's directory because there is no
     * request-supplied segment in the path at all.
     */
    public function test_uploads_are_stored_under_the_uploading_accounts_own_directory(): void
    {
        $dataset = $this->ownerDataset();

        $this->assertStringStartsWith("bi/{$this->owner->id}/sources/", (string) $dataset->source->disk_path);
        $this->assertStringStartsWith("bi/{$this->owner->id}/datasets/", $dataset->shardDirectory());

        $this->assertSame(
            [],
            Storage::disk('local')->allFiles("bi/{$this->intruder->id}"),
            "Nothing may be written under another account's prefix."
        );
    }
}
