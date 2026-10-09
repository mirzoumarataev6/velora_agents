<?php

namespace Tests\Feature\Bi;

use App\Jobs\Bi\NormalizeSourceJob;
use App\Models\BiDataset;
use App\Models\BiSource;
use App\Models\User;
use App\Services\Bi\BiIngestionException;
use App\Services\Bi\IngestErrorPresenter;
use App\Services\Bi\Ingestion\SourceNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsBiFixtures;
use Tests\Support\DropsAnalyticalTables;
use Tests\TestCase;

/**
 * Every way a real upload goes wrong, and the promise that each one produces a
 * CLASSIFIED code the owner can be told about.
 *
 * Two things are asserted for every failure, not one:
 *
 *   1. the right code, so the owner gets the right advice; and
 *   2. that the code resolves to a real translated sentence in all three locales.
 *
 * The second matters as much as the first. A perfectly classified failure whose
 * string was never written shows the owner `too_many_columns`, which is worse than
 * a generic message — so the parity check is part of the error-handling test rather
 * than a separate concern.
 */
class MalformedFileTest extends TestCase
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
     * Normalize and assert the failure is classified as `$code`, then assert that
     * code says something real in ru, tg and en.
     */
    private function assertFailsWith(string $code, BiSource $source): BiIngestionException
    {
        try {
            app(SourceNormalizer::class)->normalize($source);
        } catch (BiIngestionException $e) {
            $this->assertSame($code, $e->reason, "Expected [{$code}], got [{$e->reason}]: {$e->getMessage()}");

            $this->assertTranslatedInEveryLocale($code, $source);

            return $e;
        }

        $this->fail("Expected ingestion to fail with [{$code}], but it succeeded.");
    }

    private function assertTranslatedInEveryLocale(string $code, BiSource $source): void
    {
        $source->forceFill(['error_code' => $code])->save();
        $presenter = app(IngestErrorPresenter::class);
        $original = app()->getLocale();

        try {
            foreach (['ru', 'tg', 'en'] as $locale) {
                app()->setLocale($locale);

                $sentence = (string) $presenter->sentence($source);

                $this->assertNotSame('', $sentence);
                $this->assertStringNotContainsString(
                    'bi.errors.',
                    $sentence,
                    "[{$code}] has no {$locale} translation — the owner would be shown the raw key."
                );
                // Not the generic fallback: a code with no string of its own would
                // silently render as "внутренняя ошибка" and lose its advice.
                $this->assertNotSame(
                    __('bi.errors.internal_error'),
                    $sentence,
                    "[{$code}] must have its own {$locale} sentence, not fall back to the generic one."
                );
                // A placeholder left unfilled is a visible `:limit` in the UI.
                $this->assertDoesNotMatchRegularExpression('/:(size|limit|format|count)\b/', $sentence);
                // A real sentence rather than a fragment. The floor is low because
                // one of these legitimately IS short — "Файл пустой." needs no
                // advice attached — so this catches an empty or stub string without
                // dictating a length the shortest honest message cannot meet.
                $this->assertGreaterThan(10, mb_strlen($sentence));
            }
        } finally {
            app()->setLocale($original);
        }
    }

    /**
     * EVERY error code has a sentence in every locale — checked by reflection over the
     * constants rather than one case at a time.
     *
     * This exists because the per-case tests below only cover codes some test happens
     * to provoke. `connection_failed` was added with the live-connection work and had
     * no translation at all; the owner would have been shown the generic
     * "internal error" instead of "check the server is reachable". Enumerating the
     * constants makes that impossible to forget for the next one.
     */
    public function test_every_error_code_has_a_sentence_in_every_locale(): void
    {
        $codes = (new \ReflectionClass(BiIngestionException::class))->getConstants();

        $this->assertGreaterThan(10, count($codes), 'The reflection should be finding the codes.');

        $original = app()->getLocale();

        try {
            foreach (['ru', 'tg', 'en'] as $locale) {
                app()->setLocale($locale);

                foreach ($codes as $constant => $code) {
                    $key = 'bi.errors.'.$code;
                    $sentence = __($key, ['size' => 100, 'limit' => 150, 'format' => 'CSV', 'count' => 1]);

                    $this->assertNotSame(
                        $key,
                        $sentence,
                        "BiIngestionException::{$constant} ('{$code}') has no {$locale} translation."
                    );
                    $this->assertDoesNotMatchRegularExpression(
                        '/:(size|limit|format|count)\b/',
                        $sentence,
                        "BiIngestionException::{$constant} leaves a placeholder unfilled in {$locale}."
                    );
                }
            }
        } finally {
            app()->setLocale($original);
        }

        // And the generic fallback itself, which every unknown code resolves to.
        $this->assertNotSame('bi.errors.internal_error', __('bi.errors.internal_error'));
    }

    public function test_an_unsupported_extension_is_refused_by_name(): void
    {
        $this->assertFailsWith(
            BiIngestionException::UNSUPPORTED_FORMAT,
            $this->sourceWithContent($this->user, 'anything', 'pdf')
        );
    }

    public function test_an_empty_file_is_refused(): void
    {
        $this->assertFailsWith(
            BiIngestionException::EMPTY_FILE,
            $this->sourceWithContent($this->user, '', 'csv')
        );
    }

    public function test_a_missing_file_is_refused_rather_than_crashing(): void
    {
        $source = $this->sourceWithContent($this->user, 'Товар,Цена', 'csv');

        // The staged copy has gone — a disk that was cleared, or a half-finished
        // upload. It must be a sentence, not a stack trace.
        Storage::disk('local')->delete((string) $source->disk_path);

        $this->assertFailsWith(BiIngestionException::FILE_UNREADABLE, $source);
    }

    public function test_a_file_over_the_size_budget_is_refused_before_it_is_parsed(): void
    {
        config(['agent.bi.ingest.max_file_size_kb' => 1]);

        $source = $this->csvSource($this->user, [['Товар'], ['Куртка']]);
        $source->forceFill(['bytes' => 5 * 1024 * 1024])->save();

        $this->assertFailsWith(BiIngestionException::FILE_TOO_LARGE, $source);
    }

    /**
     * The commonest real confusion: a file whose extension does not match its
     * bytes. A CSV renamed to .xlsx, or an HTML table Excel saved with the wrong
     * suffix. The message has to name the format we TRIED, or it is a mystery.
     */
    public function test_a_csv_renamed_as_xlsx_is_refused_as_corrupt(): void
    {
        $this->assertFailsWith(
            BiIngestionException::CORRUPT_FILE,
            $this->sourceWithContent($this->user, "Товар,Цена\nКуртка,1500\n", 'xlsx')
        );
    }

    public function test_truncated_spreadsheet_bytes_are_refused_as_corrupt(): void
    {
        // A zip header and then nothing — an interrupted download.
        $this->assertFailsWith(
            BiIngestionException::CORRUPT_FILE,
            $this->sourceWithContent($this->user, "PK\x03\x04".str_repeat("\x00", 64), 'xlsx')
        );
    }

    public function test_binary_masquerading_as_csv_is_refused_for_its_encoding(): void
    {
        $this->assertFailsWith(
            BiIngestionException::ENCODING_UNREADABLE,
            $this->sourceWithContent($this->user, "\x89PNG\r\n\x1a\n".random_bytes(64), 'csv')
        );
    }

    public function test_a_header_with_no_data_rows_is_refused(): void
    {
        $this->assertFailsWith(
            BiIngestionException::NO_ROWS,
            $this->sourceWithContent($this->user, "Дата,Товар,Сумма\r\n", 'csv')
        );
    }

    public function test_an_entirely_blank_file_is_refused(): void
    {
        $this->assertFailsWith(
            BiIngestionException::NO_ROWS,
            $this->sourceWithContent($this->user, ",,\r\n,,\r\n,,\r\n", 'csv')
        );
    }

    /**
     * A hard refusal, deliberately not a truncation: silently dropping columns 151
     * onward would drop whichever column the owner actually wanted.
     */
    public function test_too_many_columns_is_refused_rather_than_truncated(): void
    {
        config(['agent.bi.ingest.max_columns' => 3]);

        $this->assertFailsWith(
            BiIngestionException::TOO_MANY_COLUMNS,
            $this->csvSource($this->user, [['A', 'B', 'C', 'D'], ['1', '2', '3', '4']])
        );
    }

    public function test_too_many_sheets_is_refused(): void
    {
        config(['agent.bi.ingest.max_sheets' => 2]);

        $this->assertFailsWith(
            BiIngestionException::TOO_MANY_SHEETS,
            $this->spreadsheetSource($this->user, [
                'A' => [['x'], ['1']],
                'B' => [['x'], ['1']],
                'C' => [['x'], ['1']],
            ])
        );
    }

    public function test_the_dataset_quota_is_enforced(): void
    {
        config(['agent.bi.ingest.max_datasets_per_owner' => 1]);

        // One dataset already exists under a different source.
        $existing = $this->csvSource($this->user, [['A'], ['1']]);
        app(SourceNormalizer::class)->normalize($existing);

        $this->assertFailsWith(
            BiIngestionException::TOO_MANY_DATASETS,
            $this->csvSource($this->user, [['B'], ['2']])
        );
    }

    /**
     * Objects with no keys at all: there is genuinely nothing here that could become
     * a column, and saying so beats producing a one-column table of nulls.
     */
    public function test_json_with_no_usable_keys_is_refused_with_advice(): void
    {
        $this->assertFailsWith(
            BiIngestionException::NOT_TABULAR,
            $this->sourceWithContent($this->user, '[{},{},{}]', 'json')
        );
    }

    /**
     * The opposite case, asserted so the boundary is recorded rather than assumed:
     * a top-level object whose values are objects IS tabular — it is a table keyed
     * by id, and the keys become the `_key` column. See JsonReader.
     */
    public function test_a_single_keyed_object_is_a_one_row_table_rather_than_a_refusal(): void
    {
        $datasets = app(SourceNormalizer::class)->normalize(
            $this->sourceWithContent($this->user, '{"meta":{"generated":"2026-03-01"}}', 'json')
        );

        $this->assertCount(1, $datasets);
        $this->assertSame(1, $datasets[0]->row_count);
        $this->assertSame(['_key', 'generated'], $datasets[0]->columns()->pluck('source_name')->all());
    }

    public function test_malformed_json_is_refused_as_corrupt(): void
    {
        $this->assertFailsWith(
            BiIngestionException::CORRUPT_FILE,
            $this->sourceWithContent($this->user, '[{"a":1},{"a":', 'json')
        );
    }

    public function test_a_legacy_xls_over_its_own_lower_budget_is_refused_with_its_own_advice(): void
    {
        config(['agent.bi.ingest.max_legacy_xls_size_kb' => 1]);

        $source = $this->legacyXlsSource($this->user, [['Товар'], ['Куртка']]);

        $exception = $this->assertFailsWith(BiIngestionException::LEGACY_XLS_TOO_LARGE, $source);

        // Its own code, because its advice is different: re-save as .xlsx and the
        // ordinary limit applies.
        $this->assertNotSame(BiIngestionException::FILE_TOO_LARGE, $exception->reason);
        $this->assertStringContainsString('.xlsx', __('bi.errors.legacy_xls_too_large'));
    }

    /**
     * The failure has to reach the ROW, or the owner sees a card that says
     * "processing" forever.
     */
    public function test_the_job_records_the_failure_on_the_source_and_does_not_retry_a_bad_file(): void
    {
        $source = $this->sourceWithContent($this->user, 'not a spreadsheet', 'xlsx');

        (new NormalizeSourceJob($source))->handle(app(SourceNormalizer::class));

        $source->refresh();

        $this->assertSame(BiSource::STATUS_FAILED, $source->status);
        $this->assertSame(BiIngestionException::CORRUPT_FILE, $source->error_code);
        $this->assertNotEmpty($source->error_detail);
    }

    /**
     * The detail shown to the owner must not leak where on our disk their upload
     * was staged — it is useless to them and exactly the sort of thing that travels
     * in a screenshot.
     */
    public function test_the_technical_detail_does_not_leak_server_paths(): void
    {
        $exception = new BiIngestionException(
            BiIngestionException::CORRUPT_FILE,
            'Could not open /var/www/storage/app/bi/7/sources/12/original.xlsx as a spreadsheet.'
        );

        $detail = $exception->detail();

        $this->assertStringNotContainsString('/var/www', $detail);
        $this->assertStringNotContainsString('storage/app', $detail);
        $this->assertStringContainsString('original.xlsx', $detail, 'The filename itself is useful and safe.');
    }

    /**
     * A sheet that contributes nothing must not fail the workbook — an "Инструкция"
     * sheet beside three real ones is completely normal.
     */
    public function test_an_empty_sheet_beside_real_ones_is_skipped_rather_than_fatal(): void
    {
        $source = $this->spreadsheetSource($this->user, [
            'Инструкция' => [['Как пользоваться отчётом']],
            'Продажи' => [['Дата', 'Сумма'], ['2026-03-01', 1500]],
        ]);

        $datasets = app(SourceNormalizer::class)->normalize($source);

        $this->assertCount(1, $datasets);
        $this->assertSame('Продажи', $datasets[0]->name);
    }

    /**
     * A dataset row must never be left behind in a non-terminal state by a failure
     * mid-sheet, or the source list shows a card stuck on "определение типов".
     */
    public function test_a_failure_mid_sheet_leaves_no_half_built_dataset_behind(): void
    {
        config(['agent.bi.ingest.max_columns' => 2]);

        $source = $this->spreadsheetSource($this->user, [
            'Хорошая' => [['A', 'B'], ['1', '2']],
            'Плохая' => [['A', 'B', 'C'], ['1', '2', '3']],
        ]);

        try {
            app(SourceNormalizer::class)->normalize($source);
            $this->fail('Expected the wide sheet to be refused.');
        } catch (BiIngestionException $e) {
            $this->assertSame(BiIngestionException::TOO_MANY_COLUMNS, $e->reason);
        }

        $this->assertSame(
            0,
            BiDataset::where('bi_source_id', $source->id)->count(),
            'A workbook is all-or-nothing: the sheets that succeeded must not be left '
            .'as datasets nothing will ever materialize.'
        );
    }

    /**
     * The shards of a rolled-back sheet go with it, or a failed upload would leave
     * the owner's data on disk under a dataset that no longer exists.
     */
    public function test_a_rolled_back_sheet_takes_its_shards_with_it(): void
    {
        config(['agent.bi.ingest.max_columns' => 2]);

        $source = $this->spreadsheetSource($this->user, [
            'Хорошая' => [['A', 'B'], ['1', '2']],
            'Плохая' => [['A', 'B', 'C'], ['1', '2', '3']],
        ]);

        try {
            app(SourceNormalizer::class)->normalize($source);
        } catch (BiIngestionException) {
            // Expected — asserted in the test above.
        }

        $this->assertSame(
            [],
            Storage::disk('local')->allFiles("bi/{$this->user->id}/datasets"),
            'No shard may outlive the dataset it belonged to.'
        );
    }
}
