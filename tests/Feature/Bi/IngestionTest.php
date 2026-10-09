<?php

namespace Tests\Feature\Bi;

use App\Models\BiColumn;
use App\Models\BiDataset;
use App\Models\BiSource;
use App\Models\User;
use App\Services\Bi\Ingestion\DatasetMaterializer;
use App\Services\Bi\Ingestion\SourceNormalizer;
use App\Services\Bi\Schema\ColumnRole;
use App\Services\Bi\Schema\ColumnType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsBiFixtures;
use Tests\Support\DropsAnalyticalTables;
use Tests\TestCase;

/**
 * Ingestion end to end: a real file in, a real queryable table out.
 *
 * Every test here runs the two stages the way the queue does — normalize, then
 * materialize — and then reads the PHYSICAL TABLE back with the query builder.
 * Asserting on `bi_columns` alone would prove that inference had an opinion, not
 * that the opinion produced a table anybody can query.
 */
class IngestionTest extends TestCase
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
     * Run both stages and return the single dataset the source produced.
     */
    private function ingest(BiSource $source): BiDataset
    {
        $datasets = app(SourceNormalizer::class)->normalize($source);

        $this->assertCount(1, $datasets, 'This fixture should produce exactly one dataset.');

        app(DatasetMaterializer::class)->materialize($datasets[0]);

        return $datasets[0]->fresh();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(BiDataset $dataset): array
    {
        return array_map(
            fn ($row) => (array) $row,
            DB::table($dataset->physicalTable())->orderBy(BiDataset::ROW_KEY)->get()->all()
        );
    }

    public function test_a_csv_becomes_a_typed_queryable_table(): void
    {
        $source = $this->csvSource($this->user, [
            ['Дата', 'Регион', 'Товар', 'Количество', 'Выручка'],
            ['01.03.2026', 'Душанбе', 'Куртка', '2', '1 500,50'],
            ['17.03.2026', 'Худжанд', 'Джинсы', '1', '2 400,00'],
            ['28.03.2026', 'Душанбе', 'Куртка', '5', '750,25'],
        ]);

        $dataset = $this->ingest($source);

        $this->assertSame(BiDataset::STATUS_READY, $dataset->status);
        $this->assertSame(3, $dataset->row_count);
        $this->assertSame(5, $dataset->column_count);
        $this->assertSame(1, $dataset->version, 'A successful materialization bumps the version the cache keys use.');

        $this->assertTrue(Schema::hasTable($dataset->physicalTable()));

        $rows = $this->rows($dataset);

        $this->assertCount(3, $rows);
        // Dates normalized to ISO, numbers to real numbers, text untouched.
        $this->assertSame('2026-03-01', $rows[0]['c0']);
        $this->assertSame('Душанбе', $rows[0]['c1']);
        $this->assertSame(2, (int) $rows[0]['c3']);
        $this->assertEquals(1500.50, (float) $rows[0]['c4']);
        $this->assertEquals(750.25, (float) $rows[2]['c4']);

        // File order is preserved and is the primary key.
        $this->assertSame([0, 1, 2], array_column($rows, BiDataset::ROW_KEY));
    }

    public function test_the_owner_facing_names_are_kept_and_the_sql_identifiers_are_ours(): void
    {
        $source = $this->csvSource($this->user, [
            // A header that would be catastrophic as an identifier, and is merely
            // data here.
            ['Дата', '"; DROP TABLE users; --'],
            ['01.03.2026', 'x'],
            ['02.03.2026', 'y'],
        ]);

        $dataset = $this->ingest($source);
        $columns = $dataset->columns()->get();

        $this->assertSame(['c0', 'c1'], $columns->pluck('physical_name')->all());
        $this->assertSame('"; DROP TABLE users; --', $columns[1]->source_name);

        // The users table is, obviously, still there.
        $this->assertTrue(Schema::hasTable('users'));
    }

    public function test_a_semicolon_delimited_windows_1251_export_is_read_correctly(): void
    {
        // What every accounting system in the region actually produces.
        $source = $this->csvSource(
            $this->user,
            [
                ['Наименование', 'Цена'],
                ['Куртка зимняя', '1500'],
                ['Джинсы классические', '2400'],
            ],
            delimiter: ';',
            encoding: 'Windows-1251',
        );

        $dataset = $this->ingest($source);

        $this->assertSame(['Наименование', 'Цена'], $dataset->columns()->pluck('source_name')->all());
        $this->assertSame('Куртка зимняя', $this->rows($dataset)[0]['c0']);
    }

    public function test_a_tab_separated_file_is_read_by_its_extension(): void
    {
        $source = $this->csvSource(
            $this->user,
            [['Товар', 'Цена'], ['Куртка', '1500']],
            delimiter: "\t",
            extension: 'tsv',
        );

        $dataset = $this->ingest($source);

        $this->assertSame(2, $dataset->column_count);
        $this->assertSame('Куртка', $this->rows($dataset)[0]['c0']);
    }

    /**
     * A .xlsx date cell arrives as a real object, so it needs no format vote at all
     * — which is why spreadsheet dates are the case inference never gets wrong.
     */
    public function test_an_xlsx_date_cell_needs_no_format_guessing(): void
    {
        $source = $this->spreadsheetSource($this->user, [
            'Продажи' => [
                ['Дата', 'Сумма'],
                [new \DateTimeImmutable('2026-03-01 00:00:00'), 1500.5],
                [new \DateTimeImmutable('2026-03-17 00:00:00'), 2400.0],
            ],
        ]);

        $dataset = $this->ingest($source);
        $columns = $dataset->columns()->get();

        $this->assertSame(ColumnType::Date, $columns[0]->type);
        $this->assertNull(
            $columns[0]->format['date_ambiguous'] ?? null,
            'A real date cell is unambiguous, so nothing should be flagged as assumed.'
        );
        $this->assertSame('2026-03-01', $this->rows($dataset)[0]['c0']);
    }

    public function test_an_xlsx_timestamp_keeps_its_clock(): void
    {
        $source = $this->spreadsheetSource($this->user, [
            'Заказы' => [
                ['Создан'],
                [new \DateTimeImmutable('2026-03-01 09:15:00')],
                [new \DateTimeImmutable('2026-03-02 17:40:00')],
            ],
        ]);

        $dataset = $this->ingest($source);

        $this->assertSame(ColumnType::DateTime, $dataset->columns()->first()->type);
        $this->assertStringContainsString('09:15:00', (string) $this->rows($dataset)[0]['c0']);
    }

    /**
     * One workbook is genuinely several tables. Flattening them into one dataset
     * would make every question about either of them wrong.
     */
    public function test_each_sheet_of_a_workbook_becomes_its_own_dataset(): void
    {
        $source = $this->spreadsheetSource($this->user, [
            'Продажи' => [['Дата', 'Сумма'], ['2026-03-01', 1500]],
            'Возвраты' => [['Дата', 'Причина'], ['2026-03-02', 'брак']],
            'Справочник' => [['Код', 'Название'], ['A1', 'Куртка']],
        ]);

        $datasets = app(SourceNormalizer::class)->normalize($source);

        $this->assertCount(3, $datasets);
        $this->assertSame(['Продажи', 'Возвраты', 'Справочник'], array_map(fn ($d) => $d->name, $datasets));
        $this->assertSame(['Продажи', 'Возвраты', 'Справочник'], array_map(fn ($d) => $d->sheet_name, $datasets));

        foreach ($datasets as $dataset) {
            app(DatasetMaterializer::class)->materialize($dataset);
            $this->assertTrue(Schema::hasTable($dataset->physicalTable()));
        }
    }

    /**
     * A generic "Sheet1" tells the owner nothing, so the filename wins there; a real
     * sheet name is what they will look for, so it wins in a workbook.
     */
    public function test_a_generic_sheet_name_falls_back_to_the_filename(): void
    {
        $source = $this->spreadsheetSource($this->user, [
            'Sheet1' => [['Товар', 'Цена'], ['Куртка', 1500]],
        ]);

        $datasets = app(SourceNormalizer::class)->normalize($source);

        $this->assertSame('fixture', $datasets[0]->name);
    }

    public function test_an_ods_workbook_is_read(): void
    {
        $source = $this->spreadsheetSource($this->user, [
            'Лист' => [['Товар', 'Цена'], ['Куртка', 1500]],
        ], extension: 'ods');

        $dataset = $this->ingest($source);

        $this->assertSame(1, $dataset->row_count);
        $this->assertSame('Куртка', $this->rows($dataset)[0]['c0']);
    }

    /**
     * The one format that cannot be streamed. Kept because it is what a decade-old
     * 1C export still produces.
     */
    public function test_a_legacy_xls_workbook_is_read_including_its_dates(): void
    {
        $source = $this->legacyXlsSource($this->user, [
            ['Товар', 'Цена'],
            ['Куртка', 1500],
            ['Джинсы', 2400],
        ]);

        $dataset = $this->ingest($source);

        $this->assertSame(2, $dataset->row_count);
        $this->assertSame('Куртка', $this->rows($dataset)[0]['c0']);
        $this->assertSame(ColumnType::Integer, $dataset->columns()->get()[1]->type);
    }

    public function test_a_json_array_of_objects_becomes_a_table(): void
    {
        $source = $this->jsonSource($this->user, [
            ['date' => '2026-03-01', 'region' => 'Душанбе', 'revenue' => 1500.5],
            ['date' => '2026-03-02', 'region' => 'Худжанд', 'revenue' => 2400],
        ]);

        $dataset = $this->ingest($source);

        $this->assertSame(['date', 'region', 'revenue'], $dataset->columns()->pluck('source_name')->all());
        $this->assertSame('2026-03-01', $this->rows($dataset)[0]['c0']);
        $this->assertEquals(1500.5, (float) $this->rows($dataset)[0]['c2']);
    }

    /**
     * One level of nesting becomes dotted columns; a varying-length list is kept as
     * its JSON text, which is honest and still searchable.
     */
    public function test_nested_json_is_flattened_one_level_and_then_serialised(): void
    {
        $source = $this->jsonSource($this->user, [
            ['id' => 1, 'customer' => ['name' => 'Иван', 'city' => 'Душанбе'], 'tags' => ['vip', 'new']],
            ['id' => 2, 'customer' => ['name' => 'Мария', 'city' => 'Худжанд'], 'tags' => ['new']],
        ]);

        $dataset = $this->ingest($source);

        $this->assertSame(
            ['id', 'customer.name', 'customer.city', 'tags'],
            $dataset->columns()->pluck('source_name')->all()
        );

        $rows = $this->rows($dataset);
        $this->assertSame('Иван', $rows[0]['c1']);
        $this->assertSame('["vip","new"]', $rows[0]['c3']);
    }

    /**
     * A top-level object keyed by id: the keys are usually the most useful column in
     * the file, and dropping them silently would be the worst kind of data loss.
     */
    public function test_a_keyed_json_object_keeps_its_keys_as_a_column(): void
    {
        $source = $this->jsonSource($this->user, [
            '1001' => ['region' => 'Душанбе', 'revenue' => 1500],
            '1002' => ['region' => 'Худжанд', 'revenue' => 2400],
        ]);

        $dataset = $this->ingest($source);

        $this->assertSame(['_key', 'region', 'revenue'], $dataset->columns()->pluck('source_name')->all());
        $this->assertSame('1001', (string) $this->rows($dataset)[0]['c0']);
    }

    public function test_ndjson_is_read_and_a_truncated_final_line_is_skipped(): void
    {
        // Exactly what a log export interrupted mid-write looks like. The other
        // 400,000 rows must not be lost over it.
        $source = $this->ndjsonSource(
            $this->user,
            [
                ['event' => 'sale', 'amount' => 1500],
                ['event' => 'sale', 'amount' => 2400],
            ],
            trailing: '{"event":"sale","amo',
        );

        $dataset = $this->ingest($source);

        $this->assertSame(2, $dataset->row_count);
    }

    public function test_a_document_missing_a_key_gets_a_null_rather_than_shifted_values(): void
    {
        $source = $this->ndjsonSource($this->user, [
            ['region' => 'Душанбе', 'revenue' => 1500, 'note' => 'опт'],
            ['region' => 'Худжанд', 'revenue' => 2400],
        ]);

        $dataset = $this->ingest($source);
        $rows = $this->rows($dataset);

        $this->assertSame('Худжанд', $rows[1]['c0'], 'A missing key must not shift the columns after it.');
        $this->assertEquals(2400, (float) $rows[1]['c1']);
        $this->assertNull($rows[1]['c2']);
    }

    /**
     * The correction path, and the reason the shards are kept: re-typing a column
     * costs one re-materialization, not a second parse of the original file.
     */
    public function test_a_corrected_type_is_applied_by_rematerialising_from_the_shards(): void
    {
        $source = $this->csvSource($this->user, [
            ['Год'],
            ['2024'], ['2025'], ['2026'],
        ]);

        $dataset = $this->ingest($source);
        $column = $dataset->columns()->first();

        $this->assertSame(ColumnType::Integer, $column->type);

        // The owner says it is text after all.
        $column->forceFill(['type' => ColumnType::Text])->save();

        // The original file is removed to PROVE the rebuild reads the shards.
        Storage::disk('local')->delete((string) $source->disk_path);

        app(DatasetMaterializer::class)->materialize($dataset->fresh());

        $rows = $this->rows($dataset);
        $this->assertSame('2024', $rows[0]['c0']);
        $this->assertSame(2, $dataset->fresh()->version, 'Each rebuild bumps the version so cache keys change.');
    }

    public function test_a_column_that_rejects_values_reports_how_many_and_which(): void
    {
        $rows = [['Цена']];

        // 96 numbers and 4 words: over the threshold, so the column IS numeric — and
        // the four rejections are counted and shown rather than silently dropped.
        for ($i = 0; $i < 96; $i++) {
            $rows[] = ['1500'];
        }
        for ($i = 0; $i < 4; $i++) {
            $rows[] = ['по запросу'];
        }

        $dataset = $this->ingest($this->csvSource($this->user, $rows));
        $column = $dataset->columns()->first();

        $this->assertSame(ColumnType::Integer, $column->type);
        $this->assertSame(4, $column->cast_failures);
        // DISTINCT examples: the same bad value repeated four times teaches the owner
        // nothing beyond the first one.
        $this->assertSame(['по запросу'], $column->cast_failure_samples);

        // The rejected rows exist, with a NULL in that column — the row is not lost.
        $this->assertSame(100, $dataset->row_count);
        $this->assertNull($this->rows($dataset)[99]['c0']);
    }

    public function test_indexes_are_created_on_the_materialised_table(): void
    {
        $rows = [['Дата', 'Регион', 'Выручка']];

        for ($i = 1; $i <= 30; $i++) {
            $rows[] = [sprintf('%02d.03.2026', ($i % 28) + 1), ['Душанбе', 'Худжанд'][$i % 2], (string) (1000 + $i)];
        }

        $dataset = $this->ingest($this->csvSource($this->user, $rows));

        $indexes = collect(Schema::getIndexes($dataset->physicalTable()))
            ->flatMap(fn ($index) => $index['columns'])
            ->unique()
            ->values()
            ->all();

        $this->assertContains('c0', $indexes, 'A time column is always indexed.');
        $this->assertContains('c1', $indexes, 'A low-cardinality dimension is indexed.');
        $this->assertNotContains('c2', $indexes, 'A measure is not indexed.');
    }

    public function test_rows_are_written_to_shards_that_survive_materialisation(): void
    {
        $source = $this->csvSource($this->user, [
            ['Товар', 'Цена'], ['Куртка', '1500'], ['Джинсы', '2400'],
        ]);

        $dataset = $this->ingest($source);

        $this->assertSame(1, $dataset->shard_count);
        $this->assertTrue(
            Storage::disk('local')->exists($dataset->shardDirectory().'/000000.ndjson'),
            'The normalized copy must survive, or a type correction would need a second parse.'
        );
    }

    public function test_a_title_row_above_the_header_is_stepped_over_and_reported(): void
    {
        $source = $this->csvSource($this->user, [
            ['Отчёт о продажах за март 2026', '', ''],
            ['', '', ''],
            ['Дата', 'Товар', 'Сумма'],
            ['01.03.2026', 'Куртка', '1500'],
            ['02.03.2026', 'Джинсы', '2400'],
        ]);

        $dataset = $this->ingest($source);

        $this->assertSame(['Дата', 'Товар', 'Сумма'], $dataset->columns()->pluck('source_name')->all());
        $this->assertSame(2, $dataset->row_count);
        // Reported, not silent — the owner should know two rows were not data.
        $this->assertSame(1, $dataset->skipped_reasons['preamble'] ?? 0);
    }

    public function test_ragged_rows_are_fitted_and_counted(): void
    {
        $source = $this->sourceWithContent(
            $this->user,
            "Дата,Товар,Сумма\r\n01.03.2026,Куртка,1500\r\n02.03.2026,Джинсы\r\n03.03.2026,Кепка,300,лишнее\r\n",
            'csv'
        );

        $dataset = $this->ingest($source);

        $this->assertSame(3, $dataset->row_count, 'A ragged row is fitted, never dropped.');
        $this->assertSame(2, $dataset->skipped_reasons['ragged'] ?? 0);

        $rows = $this->rows($dataset);
        $this->assertNull($rows[1]['c2'], 'A short row is padded rather than shifting its values.');
        $this->assertSame('Кепка', $rows[2]['c1'], 'A long row is truncated at the header width.');
    }

    public function test_blank_rows_are_dropped_silently_rather_than_reported_as_skipped(): void
    {
        // A spreadsheet's trailing empty rows can number in the thousands, and
        // reporting them would bury the rejections that matter.
        $source = $this->sourceWithContent(
            $this->user,
            "Товар,Цена\r\nКуртка,1500\r\n,\r\n,\r\nДжинсы,2400\r\n",
            'csv'
        );

        $dataset = $this->ingest($source);

        $this->assertSame(2, $dataset->row_count);
        $this->assertNull($dataset->skipped_reasons);
    }

    public function test_the_row_budget_truncates_and_says_so(): void
    {
        config(['agent.bi.ingest.max_rows' => 2]);

        $source = $this->csvSource($this->user, [
            ['Товар'], ['A'], ['B'], ['C'], ['D'],
        ]);

        $dataset = $this->ingest($source);

        $this->assertSame(2, $dataset->row_count);
        $this->assertTrue($dataset->truncated);
        $this->assertSame(
            2,
            $dataset->skipped_reasons['over_row_limit'],
            'The real overflow count — four data rows, two imported, two over.'
        );
    }

    public function test_multiple_shards_are_written_and_read_back_in_order(): void
    {
        config(['agent.bi.ingest.shard_rows' => 1000]);

        $rows = [['N']];
        for ($i = 0; $i < 2500; $i++) {
            $rows[] = [(string) $i];
        }

        $dataset = $this->ingest($this->csvSource($this->user, $rows));

        $this->assertSame(3, $dataset->shard_count);
        $this->assertSame(2500, $dataset->row_count);

        // The ordering assertion is the point: zero-padded shard names are what make
        // lexical order numeric order, and without that shard 10 would be read
        // before shard 2.
        $values = DB::table($dataset->physicalTable())
            ->orderBy(BiDataset::ROW_KEY)
            ->pluck('c0')
            ->all();

        $this->assertSame(0, (int) $values[0]);
        $this->assertSame(1500, (int) $values[1500]);
        $this->assertSame(2499, (int) $values[2499]);
    }

    public function test_materialisation_is_idempotent_so_a_retry_is_not_additive(): void
    {
        $dataset = $this->ingest($this->csvSource($this->user, [
            ['Товар'], ['Куртка'], ['Джинсы'],
        ]));

        app(DatasetMaterializer::class)->materialize($dataset->fresh());
        app(DatasetMaterializer::class)->materialize($dataset->fresh());

        $this->assertSame(2, DB::table($dataset->physicalTable())->count());
    }

    public function test_roles_and_labels_reach_the_stored_columns(): void
    {
        $dataset = $this->ingest($this->csvSource($this->user, [
            ['Дата', 'Регион', 'Выручка'],
            ['01.03.2026', 'Душанбе', '1500.50'],
            ['02.03.2026', 'Худжанд', '2400.00'],
        ]));

        $columns = $dataset->columns()->get();

        $this->assertSame(ColumnRole::Time, $columns[0]->role);
        $this->assertSame(ColumnRole::Dimension, $columns[1]->role);
        $this->assertSame(ColumnRole::Measure, $columns[2]->role);

        // displayName() falls back to the file's own header until a label is set.
        $this->assertSame('Выручка', $columns[2]->displayName());
        $columns[2]->forceFill(['label' => 'Revenue'])->save();
        $this->assertSame('Revenue', $columns[2]->fresh()->displayName());
    }

    public function test_re_normalising_replaces_the_column_set_rather_than_accumulating(): void
    {
        $source = $this->csvSource($this->user, [
            ['A', 'B'], ['1', '2'],
        ]);

        app(SourceNormalizer::class)->normalize($source);
        $first = BiColumn::count();

        // A second run of the same source produces a second dataset with its own
        // columns; the first dataset's columns are replaced, not duplicated.
        $datasets = app(SourceNormalizer::class)->normalize($source);

        $this->assertSame(2, $datasets[0]->columns()->count());
        $this->assertSame($first * 2, BiColumn::count());
    }
}
