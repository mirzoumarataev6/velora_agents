<?php

namespace Tests\Feature\Bi;

use App\Jobs\Bi\BiJob;
use App\Jobs\Bi\DropDatasetTableJob;
use App\Jobs\Bi\MaterializeDatasetJob;
use App\Jobs\Bi\NormalizeSourceJob;
use App\Models\BiDataset;
use App\Models\BiSource;
use App\Models\User;
use App\Services\Bi\Ingestion\DatasetMaterializer;
use App\Services\Bi\Ingestion\SourceNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\BuildsBiFixtures;
use Tests\Support\DropsAnalyticalTables;
use Tests\TestCase;

/**
 * The upload endpoint, the queue contract, and the cleanup that makes "delete" mean
 * it.
 *
 * The queue assertions are not incidental. A 100MB import that lands on the
 * `default` queue would sit in front of customers waiting for a reply, which is the
 * outage this whole two-pool arrangement exists to prevent — so "which queue" is a
 * behaviour worth a test rather than a comment.
 */
class SourceUploadTest extends TestCase
{
    use BuildsBiFixtures, DropsAnalyticalTables, RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->user = User::factory()->create();
    }

    private function upload(string $name = 'sales.csv', ?string $content = null): TestResponse
    {
        $content ??= "Дата,Товар,Сумма\r\n01.03.2026,Куртка,1500\r\n";

        return $this->actingAs($this->user)->post(route('bi.sources.store'), [
            'file' => UploadedFile::fake()->createWithContent($name, $content),
        ]);
    }

    /**
     * The page renders in all three locales with no untranslated key leaking through.
     *
     * A missing string in Blade renders as the KEY — `bi.schema.rebuild_warning` sitting
     * in the middle of a panel — which is both wrong and unmistakably broken-looking.
     * Asserting the absence of the namespace prefix catches every one of them at once,
     * including the ones inside the drawer's markup that no other test opens.
     */
    public function test_the_page_renders_in_every_locale_without_leaking_a_translation_key(): void
    {
        $this->csvSource($this->user, [['Товар', 'Цена'], ['Куртка', '1500']]);

        foreach (['ru', 'tg', 'en'] as $locale) {
            $this->app->setLocale($locale);

            $response = $this->actingAs($this->user)->get(route('bi.sources.index'));

            $response->assertOk();

            $html = $response->getContent();

            // The i18n bundle legitimately embeds dotted key paths as `data-i18n`
            // attributes and inside its JSON payload, so those are stripped before
            // looking for a key that leaked into visible text.
            $visible = preg_replace('/<script\b[^>]*>.*?<\/script>/su', '', $html) ?? $html;
            $visible = preg_replace('/data-i18n(?:-al|-ph)?="[^"]*"/', '', $visible) ?? $visible;

            $this->assertDoesNotMatchRegularExpression(
                '/\bbi\.(sources|schema|connect|status|notes|errors|types|roles|validation)\./',
                $visible,
                "An untranslated bi.* key is visible on the page in [{$locale}]."
            );
        }
    }

    public function test_the_page_renders_and_appears_in_the_sidebar(): void
    {
        $response = $this->actingAs($this->user)->get(route('bi.sources.index'));

        $response->assertOk();
        $response->assertSee(__('bi.data.title'));
        // The section has to be reachable, not merely routable.
        $response->assertSee(route('bi.sources.index'));
        $response->assertSee(__('agents.nav.bi'));
    }

    /**
     * The request returns immediately and the work is queued. Parsing inline would
     * hold the browser for minutes and die behind a proxy, leaving the owner with no
     * idea whether their file arrived.
     */
    public function test_an_upload_is_stored_and_queued_rather_than_parsed_inline(): void
    {
        Queue::fake();

        $this->upload()->assertRedirect();

        $source = BiSource::sole();

        $this->assertSame($this->user->id, $source->user_id);
        $this->assertSame(BiSource::STATUS_PENDING, $source->status);
        $this->assertSame('sales.csv', $source->original_filename);
        $this->assertSame('csv', $source->extension);
        $this->assertGreaterThan(0, $source->bytes);

        Storage::disk('local')->assertExists((string) $source->disk_path);
        $this->assertStringStartsWith("bi/{$this->user->id}/sources/{$source->id}/", (string) $source->disk_path);

        Queue::assertPushed(NormalizeSourceJob::class);
    }

    /**
     * THE QUEUE SPLIT. Ingestion must never share a FIFO with customer replies.
     */
    public function test_ingestion_is_queued_on_the_ingest_queue_and_never_on_default(): void
    {
        Queue::fake();

        $this->upload();

        Queue::assertPushed(NormalizeSourceJob::class, function (NormalizeSourceJob $job) {
            return $job->queue === BiJob::QUEUE;
        });

        $this->assertSame('ingest', BiJob::QUEUE);
        $this->assertNotSame('default', BiJob::QUEUE);
    }

    public function test_every_bi_job_runs_on_the_ingest_queue(): void
    {
        $dataset = BiDataset::factory()->create(['user_id' => $this->user->id]);
        $source = BiSource::factory()->create(['user_id' => $this->user->id]);

        foreach ([
            new NormalizeSourceJob($source),
            new MaterializeDatasetJob($dataset),
            new DropDatasetTableJob('bi_ds_1'),
        ] as $job) {
            $this->assertSame(
                BiJob::QUEUE,
                $job->queue,
                $job::class.' must not land on the queue that serves customer messages.'
            );
        }
    }

    public function test_a_materialise_job_is_dispatched_per_sheet_after_normalising(): void
    {
        Queue::fake();

        $source = $this->spreadsheetSource($this->user, [
            'Продажи' => [['Дата', 'Сумма'], ['2026-03-01', 1500]],
            'Возвраты' => [['Дата', 'Причина'], ['2026-03-02', 'брак']],
        ], extension: 'xlsx');

        (new NormalizeSourceJob($source))->handle(app(SourceNormalizer::class));

        $this->assertSame(BiSource::STATUS_READY, $source->fresh()->status);
        // One independently retryable unit of work per sheet, rather than one
        // all-or-nothing job for the workbook.
        Queue::assertPushed(MaterializeDatasetJob::class, 2);
    }

    public function test_an_unsupported_extension_is_refused_by_validation_with_a_translated_message(): void
    {
        Queue::fake();

        $response = $this->upload('report.pdf', '%PDF-1.4 fake');

        $response->assertSessionHasErrors('file');
        $this->assertSame(__('bi.validation.file_extension'), session('errors')->first('file'));
        $this->assertSame(0, BiSource::count());
        Queue::assertNothingPushed();
    }

    public function test_a_file_over_the_limit_is_refused_by_validation_before_it_is_stored(): void
    {
        config(['agent.bi.ingest.max_file_size_kb' => 1]);
        Queue::fake();

        $response = $this->actingAs($this->user)->post(route('bi.sources.store'), [
            'file' => UploadedFile::fake()->create('big.csv', 4096),
        ]);

        $response->assertSessionHasErrors('file');
        $this->assertSame(0, BiSource::count());
        $this->assertSame([], Storage::disk('local')->allFiles('bi'));
    }

    public function test_a_missing_file_is_refused(): void
    {
        $this->actingAs($this->user)
            ->post(route('bi.sources.store'), [])
            ->assertSessionHasErrors('file');
    }

    /**
     * Checked before the file is written: an owner at their quota should be told so,
     * not have 100MB of their data staged on our disk and then be refused by the job.
     */
    public function test_the_dataset_quota_is_enforced_before_the_upload_is_stored(): void
    {
        config(['agent.bi.ingest.max_datasets_per_owner' => 1]);
        BiDataset::factory()->create(['user_id' => $this->user->id]);

        Queue::fake();

        $response = $this->upload();

        $response->assertSessionHasErrors('file');
        $this->assertSame(1, BiSource::count(), 'Only the factory source, not a new one.');
        Queue::assertNothingPushed();
    }

    public function test_the_status_endpoint_reports_progress_and_translated_errors(): void
    {
        $source = BiSource::factory()->failed('corrupt_file')->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)->getJson(route('bi.sources.status'));

        $response->assertOk();
        $response->assertJsonPath('sources.0.id', $source->id);
        $response->assertJsonPath('sources.0.status', BiSource::STATUS_FAILED);
        $response->assertJsonPath('sources.0.status_label', __('bi.status.failed'));

        $error = $response->json('sources.0.error');
        $this->assertNotNull($error);
        $this->assertStringNotContainsString('bi.errors.', $error, 'The poller must not show a raw key.');
        $this->assertNotNull($response->json('sources.0.error_detail'));
    }

    public function test_a_failed_source_can_be_retried_from_the_start(): void
    {
        Queue::fake();

        $source = BiSource::factory()->failed()->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)->post(route('bi.sources.retry', $source))->assertRedirect();

        $source->refresh();
        $this->assertSame(BiSource::STATUS_PENDING, $source->status);
        $this->assertNull($source->error_code);
        Queue::assertPushed(NormalizeSourceJob::class);
    }

    /**
     * A source whose shards already exist is retried at the materialization step —
     * there is no reason to re-read a 60MB .xlsx when the normalized copy is on disk.
     */
    public function test_a_retry_resumes_from_the_shards_when_they_exist(): void
    {
        Queue::fake();

        $source = BiSource::factory()->failed()->create(['user_id' => $this->user->id]);
        BiDataset::factory()->create([
            'user_id' => $this->user->id,
            'bi_source_id' => $source->id,
            'status' => BiDataset::STATUS_FAILED,
            'shard_count' => 2,
        ]);

        $this->actingAs($this->user)->post(route('bi.sources.retry', $source))->assertRedirect();

        Queue::assertPushed(MaterializeDatasetJob::class);
        Queue::assertNotPushed(NormalizeSourceJob::class);
        $this->assertSame(BiSource::STATUS_READY, $source->fresh()->status);
    }

    public function test_a_source_that_has_not_failed_cannot_be_retried(): void
    {
        Queue::fake();

        $source = BiSource::factory()->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)
            ->post(route('bi.sources.retry', $source))
            ->assertSessionHasErrors();

        Queue::assertNothingPushed();
    }

    /**
     * DELETION HAS TO REACH THE TABLE AND THE SHARDS. A cascade knows only about
     * rows, and a `bi_ds_*` table left behind is the owner's data still on disk with
     * nothing referencing it.
     */
    public function test_deleting_a_source_drops_its_tables_and_shards(): void
    {
        $source = $this->csvSource($this->user, [['Товар'], ['Куртка']]);
        $datasets = app(SourceNormalizer::class)->normalize($source);
        app(DatasetMaterializer::class)->materialize($datasets[0]);

        $table = $datasets[0]->physicalTable();
        $shards = $datasets[0]->shardDirectory();

        $this->assertTrue(Schema::hasTable($table));
        $this->assertNotEmpty(Storage::disk('local')->allFiles($shards));

        $this->actingAs($this->user)
            ->delete(route('bi.sources.destroy', $source))
            ->assertRedirect();

        $this->assertDatabaseMissing('bi_sources', ['id' => $source->id]);
        $this->assertDatabaseMissing('bi_datasets', ['id' => $datasets[0]->id]);
        $this->assertFalse(Schema::hasTable($table), 'The analytical table must go with its dataset.');
        $this->assertSame([], Storage::disk('local')->allFiles($shards));
        $this->assertSame([], Storage::disk('local')->allFiles($source->storageDirectory()));
    }

    /**
     * The case a SQL cascade cannot reach at all, and the one that matters most:
     * "delete my account" must not leave the account's data in a table on our disk.
     */
    public function test_deleting_an_account_drops_every_analytical_table_it_owned(): void
    {
        $source = $this->csvSource($this->user, [['Товар'], ['Куртка']]);
        $datasets = app(SourceNormalizer::class)->normalize($source);
        app(DatasetMaterializer::class)->materialize($datasets[0]);

        $table = $datasets[0]->physicalTable();
        $this->assertTrue(Schema::hasTable($table));

        $this->user->delete();

        $this->assertSame(0, BiSource::count());
        $this->assertSame(0, BiDataset::count());
        $this->assertFalse(
            Schema::hasTable($table),
            "A deleted account's analytical table must not survive the cascade."
        );
        $this->assertSame([], Storage::disk('local')->allFiles("bi/{$this->user->id}"));
    }

    public function test_deleting_a_dataset_alone_queues_its_own_table_drop(): void
    {
        Queue::fake();

        $dataset = BiDataset::factory()->create(['user_id' => $this->user->id]);
        $expected = $dataset->physicalTable();

        $dataset->delete();

        Queue::assertPushed(
            DropDatasetTableJob::class,
            fn (DropDatasetTableJob $job) => $job->table === $expected
        );
    }
}
