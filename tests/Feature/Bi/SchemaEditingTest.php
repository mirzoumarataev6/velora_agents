<?php

namespace Tests\Feature\Bi;

use App\Jobs\Bi\MaterializeDatasetJob;
use App\Models\BiDataset;
use App\Models\BiSource;
use App\Models\User;
use App\Services\Bi\Ingestion\DatasetMaterializer;
use App\Services\Bi\Ingestion\SourceNormalizer;
use App\Services\Bi\Schema\ColumnRole;
use App\Services\Bi\Schema\ColumnType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsBiFixtures;
use Tests\Support\DropsAnalyticalTables;
use Tests\TestCase;

/**
 * The schema drawer: what inference guessed, and the owner overruling it.
 *
 * This is the surface that makes the honest parts of ingestion actually useful.
 * Inference reports that it assumed a date order and that it rejected 200 values;
 * without a working correction path that reporting is just an apology. So the tests
 * here are mostly about the ROUND TRIP — edit, rebuild, and the data really changed.
 *
 * The distinction under test throughout: a type change rebuilds the table, a label
 * change does not. Getting that wrong in either direction is bad — a rebuild on every
 * typo, or a stored type that no longer matches the column's contents.
 */
class SchemaEditingTest extends TestCase
{
    use BuildsBiFixtures, DropsAnalyticalTables, RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->user = User::factory()->create();
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function ingest(array $rows): BiDataset
    {
        $source = $this->csvSource($this->user, $rows);
        $datasets = app(SourceNormalizer::class)->normalize($source);
        app(DatasetMaterializer::class)->materialize($datasets[0]);

        return $datasets[0]->fresh();
    }

    private function dataset(): BiDataset
    {
        return $this->ingest([
            ['Дата', 'Регион', 'Выручка'],
            ['01.03.2026', 'Душанбе', '1500.50'],
            ['17.03.2026', 'Худжанд', '2400.00'],
        ]);
    }

    public function test_it_returns_the_schema_with_everything_inference_measured(): void
    {
        $dataset = $this->dataset();

        $response = $this->actingAs($this->user)->getJson(route('bi.datasets.show', $dataset));

        $response->assertOk();
        $response->assertJsonPath('name', $dataset->name);
        $response->assertJsonPath('kind', BiSource::KIND_FILE);
        $response->assertJsonPath('columns.0.source_name', 'Дата');
        $response->assertJsonPath('columns.0.type', 'date');
        $response->assertJsonPath('columns.0.role', 'time');
        $response->assertJsonPath('columns.2.role', 'measure');
        $response->assertJsonPath('columns.0.overridden', false);

        // The measurements the drawer shows, and the evidence pane.
        $this->assertNotEmpty($response->json('columns.1.sample_values'));
        $this->assertCount(2, $response->json('preview'));
        $this->assertSame('Душанбе', $response->json('preview.0.c1'));
    }

    /**
     * The date-format options are offered as EXAMPLE DATES, not as `d.m.Y`. Asking an
     * owner to read PHP format characters to resolve день/месяц would defeat the point
     * of offering the choice at all.
     */
    public function test_date_format_options_are_shown_as_example_dates(): void
    {
        $dataset = $this->dataset();

        $response = $this->actingAs($this->user)->getJson(route('bi.datasets.show', $dataset));

        $labels = array_column($response->json('options.date_formats'), 'label', 'value');

        $this->assertSame('25.12.2026', $labels['d.m.Y']);
        $this->assertSame('12.25.2026', $labels['m.d.Y']);
        $this->assertSame('2026-12-25', $labels['Y-m-d']);
        // The example is deliberately a date whose day and month cannot be confused.
        $this->assertNotSame($labels['d.m.Y'], $labels['m.d.Y']);
    }

    public function test_a_label_and_description_save_without_rebuilding_anything(): void
    {
        Queue::fake();
        $dataset = $this->dataset();
        $column = $dataset->columns()->where('ordinal', 2)->sole();

        $response = $this->actingAs($this->user)->patchJson(route('bi.datasets.columns', $dataset), [
            'columns' => [
                $column->id => ['label' => 'Revenue', 'description' => 'Выручка с НДС'],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('changed', 1);
        $response->assertJsonPath('rebuilding', false);

        $column->refresh();
        $this->assertSame('Revenue', $column->label);
        $this->assertSame('Выручка с НДС', $column->description);
        $this->assertSame('Revenue', $column->displayName());

        Queue::assertNothingPushed();
    }

    public function test_a_role_change_saves_without_rebuilding(): void
    {
        Queue::fake();
        $dataset = $this->dataset();
        $column = $dataset->columns()->where('ordinal', 2)->sole();

        $this->actingAs($this->user)
            ->patchJson(route('bi.datasets.columns', $dataset), [
                'columns' => [$column->id => ['role' => 'dimension']],
            ])
            ->assertOk()
            ->assertJsonPath('rebuilding', false);

        $this->assertSame(ColumnRole::Dimension, $column->fresh()->role);
        Queue::assertNothingPushed();
    }

    /**
     * A type change DOES rebuild — and the response says so, so the UI can show
     * "перестраиваем таблицу" only when it is true.
     */
    public function test_a_type_change_queues_a_rebuild_and_reports_it(): void
    {
        Queue::fake();
        $dataset = $this->dataset();
        $column = $dataset->columns()->where('ordinal', 2)->sole();

        $response = $this->actingAs($this->user)->patchJson(route('bi.datasets.columns', $dataset), [
            'columns' => [$column->id => ['type' => 'text']],
        ]);

        $response->assertOk();
        $response->assertJsonPath('rebuilding', true);
        // Named, so the warning can say WHICH column is causing the rebuild.
        $this->assertSame(['Выручка'], $response->json('rebuild_columns'));

        $this->assertSame(BiDataset::STATUS_MATERIALIZING, $dataset->fresh()->status);
        Queue::assertPushed(MaterializeDatasetJob::class);
    }

    /**
     * THE FULL ROUND TRIP, and the reason the shards are kept: the owner re-types a
     * column and the data in the table really changes — without the original file.
     */
    public function test_a_corrected_type_changes_the_data_in_the_table(): void
    {
        $dataset = $this->ingest([['Год'], ['2024'], ['2025'], ['2026']]);
        $column = $dataset->columns()->sole();

        $this->assertSame(ColumnType::Integer, $column->type);
        $this->assertSame(2024, (int) DB::table($dataset->physicalTable())->value('c0'));

        // The original upload is deleted first, to prove the rebuild reads the
        // normalized shards rather than re-parsing the file.
        Storage::disk('local')->delete((string) $dataset->source->disk_path);

        $this->actingAs($this->user)
            ->patchJson(route('bi.datasets.columns', $dataset), [
                'columns' => [$column->id => ['type' => 'text']],
            ])
            ->assertOk();

        $fresh = $dataset->fresh();

        $this->assertSame(BiDataset::STATUS_READY, $fresh->status, 'The sync queue runs the rebuild inline.');
        $this->assertSame('2024', DB::table($fresh->physicalTable())->value('c0'));
        $this->assertSame(3, $fresh->row_count);
        $this->assertSame(2, $fresh->version, 'Each rebuild bumps the version the cache keys carry.');
    }

    /**
     * THE CASE INFERENCE CANNOT SETTLE. `03.04.2026` is April to a CIS reader and
     * March to a US one; the voter prefers day-first and flags it, and this is the
     * correction that resolves it.
     */
    public function test_correcting_an_ambiguous_date_order_reinterprets_every_row(): void
    {
        $dataset = $this->ingest([['Дата'], ['01/02/2026'], ['03/04/2026'], ['05/06/2026']]);
        $column = $dataset->columns()->sole();

        // Day-first was assumed, and the assumption is disclosed.
        $this->assertTrue($column->format['date_ambiguous']);
        $this->assertSame('d/m/Y', $column->format['date_format']);
        $this->assertSame('2026-02-01', DB::table($dataset->physicalTable())->value('c0'));

        $this->actingAs($this->user)
            ->patchJson(route('bi.datasets.columns', $dataset), [
                'columns' => [$column->id => ['date_format' => 'm/d/Y']],
            ])
            ->assertOk()
            ->assertJsonPath('rebuilding', true);

        $fresh = $dataset->fresh();

        // The same digits, now read the other way round.
        $this->assertSame('2026-01-02', DB::table($fresh->physicalTable())->value('c0'));

        $column->refresh();
        $this->assertSame('m/d/Y', $column->format['date_format']);
        $this->assertFalse(
            $column->format['date_ambiguous'],
            'Once the owner has stated the order it is no longer an assumption, so the warning must stop.'
        );
    }

    public function test_the_inferred_type_survives_an_override_so_it_can_be_put_back(): void
    {
        $dataset = $this->dataset();
        $column = $dataset->columns()->where('ordinal', 2)->sole();

        $this->actingAs($this->user)->patchJson(route('bi.datasets.columns', $dataset), [
            'columns' => [$column->id => ['type' => 'text']],
        ])->assertOk();

        $column->refresh();

        $this->assertSame(ColumnType::Text, $column->type);
        $this->assertSame(
            ColumnType::Decimal,
            $column->inferred_type,
            "The original proposal has to survive, or 'put it back' is impossible."
        );

        $response = $this->actingAs($this->user)->getJson(route('bi.datasets.show', $dataset));
        $response->assertJsonPath('columns.2.overridden', true);
        $response->assertJsonPath('columns.2.inferred_type', 'decimal');
    }

    /**
     * Re-typing changes whether a column is worth an index at all: free text is not,
     * and a measure never is.
     */
    public function test_re_typing_updates_whether_the_column_is_indexed(): void
    {
        $dataset = $this->ingest([
            ['Регион'],
            ['Душанбе'], ['Худжанд'], ['Бохтар'],
        ]);
        $column = $dataset->columns()->sole();

        $this->assertTrue($column->indexed, 'A low-cardinality dimension starts indexed.');

        $this->actingAs($this->user)->patchJson(route('bi.datasets.columns', $dataset), [
            'columns' => [$column->id => ['role' => 'measure']],
        ])->assertOk();

        $this->assertFalse($column->fresh()->indexed, 'Nothing groups by a measure.');
    }

    public function test_an_invalid_type_or_role_is_refused_with_a_translated_message(): void
    {
        $dataset = $this->dataset();
        $column = $dataset->columns()->first();

        $response = $this->actingAs($this->user)->patchJson(route('bi.datasets.columns', $dataset), [
            'columns' => [$column->id => ['type' => 'geometry']],
        ]);

        $response->assertStatus(422);
        // The error key literally contains dots (`columns.7.type`), so it has to be
        // read out of the array rather than through json()'s dot-path traversal.
        $errors = $response->json('errors');
        $this->assertSame(
            [__('bi.schema.invalid_type')],
            $errors['columns.'.$column->id.'.type']
        );

        $this->assertSame(ColumnType::Date, $column->fresh()->type, 'Nothing may change on a rejected request.');
    }

    public function test_an_arbitrary_date_format_string_is_refused(): void
    {
        $dataset = $this->dataset();
        $column = $dataset->columns()->first();

        $this->actingAs($this->user)
            ->patchJson(route('bi.datasets.columns', $dataset), [
                'columns' => [$column->id => ['date_format' => 'Y\\ZZZ']],
            ])
            ->assertStatus(422);

        $this->assertSame('d.m.Y', $column->fresh()->format['date_format']);
    }

    /**
     * A column id belonging to another dataset is not merely rejected — it is not
     * found, because the editor resolves ids against this dataset's own columns.
     */
    public function test_a_column_id_from_another_dataset_is_ignored(): void
    {
        $dataset = $this->dataset();
        $other = $this->ingest([['Прочее'], ['x'], ['y']]);
        $foreignColumn = $other->columns()->sole();

        $this->actingAs($this->user)
            ->patchJson(route('bi.datasets.columns', $dataset), [
                'columns' => [$foreignColumn->id => ['label' => 'взломано']],
            ])
            ->assertOk()
            ->assertJsonPath('changed', 0);

        $this->assertNull($foreignColumn->fresh()->label);
    }

    public function test_rebuilding_can_be_asked_for_directly(): void
    {
        $dataset = $this->dataset();

        $this->actingAs($this->user)
            ->postJson(route('bi.datasets.rematerialize', $dataset))
            ->assertOk()
            ->assertJsonPath('rebuilding', true);

        $fresh = $dataset->fresh();
        $this->assertSame(BiDataset::STATUS_READY, $fresh->status);
        $this->assertSame(2, $fresh->version);
        $this->assertSame(2, DB::table($fresh->physicalTable())->count());
    }

    /**
     * Offering a rebuild button that cannot work would be a lie. Without shards there
     * is nothing to rebuild from, and the honest instruction is to re-upload.
     */
    public function test_rebuilding_without_shards_is_refused_with_advice(): void
    {
        $dataset = BiDataset::factory()->create(['user_id' => $this->user->id, 'shard_count' => 0]);

        $response = $this->actingAs($this->user)->postJson(route('bi.datasets.rematerialize', $dataset));

        $response->assertStatus(422);
        $this->assertSame(__('bi.schema.no_shards'), $response->json('errors.dataset.0'));

        // And the drawer is told not to offer the button in the first place.
        $this->actingAs($this->user)
            ->getJson(route('bi.datasets.show', $dataset))
            ->assertJsonPath('can_rebuild', false);
    }

    public function test_a_dataset_can_be_renamed_and_deleted(): void
    {
        $dataset = $this->dataset();

        $this->actingAs($this->user)
            ->patchJson(route('bi.datasets.update', $dataset), ['name' => 'Продажи за март'])
            ->assertOk()
            ->assertJsonPath('dataset.name', 'Продажи за март');

        $table = $dataset->physicalTable();

        $this->actingAs($this->user)
            ->deleteJson(route('bi.datasets.destroy', $dataset))
            ->assertOk();

        $this->assertDatabaseMissing('bi_datasets', ['id' => $dataset->id]);
        $this->assertFalse(Schema::hasTable($table));
    }

    public function test_the_skipped_row_breakdown_is_translated_for_the_drawer(): void
    {
        $dataset = $this->ingest([
            ['Отчёт за март', '', ''],
            ['Дата', 'Товар', 'Сумма'],
            ['01.03.2026', 'Куртка', '1500'],
        ]);

        $reasons = $this->actingAs($this->user)
            ->getJson(route('bi.datasets.show', $dataset))
            ->json('skipped_reasons');

        $this->assertNotEmpty($reasons);
        foreach ($reasons as $reason) {
            $this->assertStringNotContainsString('bi.notes.', $reason, 'A raw key must never reach the drawer.');
            $this->assertStringNotContainsString(':count', $reason);
        }
    }

    /**
     * The preview reads the dataset's own physical table, which is dropped during a
     * rebuild. An empty preview is the correct answer in that window, not a 500.
     */
    public function test_the_preview_is_empty_rather_than_broken_while_a_table_is_missing(): void
    {
        $dataset = $this->dataset();

        Schema::drop($dataset->physicalTable());

        $this->actingAs($this->user)
            ->getJson(route('bi.datasets.show', $dataset))
            ->assertOk()
            ->assertJsonPath('preview', []);
    }

    /* --------------------------------------------------------------- isolation */

    public function test_another_account_cannot_read_or_edit_a_schema(): void
    {
        $dataset = $this->dataset();
        $column = $dataset->columns()->first();
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->getJson(route('bi.datasets.show', $dataset))->assertForbidden();

        $this->actingAs($intruder)->patchJson(route('bi.datasets.columns', $dataset), [
            'columns' => [$column->id => ['type' => 'text']],
        ])->assertForbidden();

        $this->actingAs($intruder)->postJson(route('bi.datasets.rematerialize', $dataset))->assertForbidden();
        $this->actingAs($intruder)->patchJson(route('bi.datasets.update', $dataset), ['name' => 'x'])->assertForbidden();
        $this->actingAs($intruder)->deleteJson(route('bi.datasets.destroy', $dataset))->assertForbidden();

        $this->assertSame(ColumnType::Date, $column->fresh()->type);
        $this->assertDatabaseHas('bi_datasets', ['id' => $dataset->id]);
    }

    public function test_a_guest_cannot_reach_the_schema_endpoints(): void
    {
        $dataset = $this->dataset();

        $this->getJson(route('bi.datasets.show', $dataset))->assertUnauthorized();
        $this->patchJson(route('bi.datasets.columns', $dataset), ['columns' => []])->assertUnauthorized();
        $this->postJson(route('bi.datasets.rematerialize', $dataset))->assertUnauthorized();
    }
}
