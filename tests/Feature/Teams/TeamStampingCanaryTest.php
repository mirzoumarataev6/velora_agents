<?php

namespace Tests\Feature\Teams;

use App\Jobs\Bi\NormalizeSourceJob;
use App\Jobs\ExtractTextJob;
use App\Models\Agent;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\KnowledgeSource;
use App\Models\Task;
use App\Models\User;
use App\Services\Llm\LlmProviderInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsBiFixtures;
use Tests\Support\DropsAnalyticalTables;
use Tests\TestCase;

/**
 * The permanent guard for Stage 2a: runs a representative slice of every
 * write path that creates rows in the 26 team-scoped tables — ordinary
 * Eloquent saves (agent, channel, conversation, CRM deal/task, including
 * the DealObserver audit row this exercises as a side effect), the two
 * explicit-stamp bulk-insert paths (catalog import's DatasetProduct rows,
 * a BI upload's BiColumn rows), and a full BI ingest through the real
 * `sync`-queue job chain (NormalizeSourceJob -> MaterializeDatasetJob) and
 * a full knowledge ingest through ExtractTextJob's chain — then asks
 * `team:verify-migration` whether any of it left a row with no team_id or
 * a team_id pointing nowhere.
 *
 * `team:verify-migration` itself is the single source of truth for "is
 * every row filled in" — this test runs it rather than re-implementing its
 * query, so the two can never drift apart. What this test adds is the
 * traffic: if a future write path (a new bulk insert, a new table) forgets
 * to stamp team_id, this is what notices before `team:verify-migration`
 * would ever be run by hand again.
 */
class TeamStampingCanaryTest extends TestCase
{
    use BuildsBiFixtures, DropsAnalyticalTables, RefreshDatabase;

    public function test_a_representative_slice_of_every_write_path_leaves_no_row_without_a_team_id(): void
    {
        Storage::fake('local');

        // User -> agent -> channel -> conversation, the ordinary Eloquent path.
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();
        $channel = Channel::factory()->for($agent)->create();
        Conversation::factory()->for($agent)->create(['channel_id' => $channel->id]);

        // CRM: a deal (which also fires DealObserver's DealActivity audit
        // row) and a task.
        $customer = Customer::factory()->for($user)->create();
        $deal = Deal::factory()->for($user)->create(['customer_id' => $customer->id]);
        Task::factory()->for($user)->create(['customer_id' => $customer->id]);

        // Catalog import: the real HTTP endpoint, through DatasetImporter's
        // explicit-stamp bulk insert of DatasetProduct rows.
        $analyze = $this->actingAs($user)->post(
            route('agents.catalog.analyze', $agent),
            ['file' => UploadedFile::fake()->createWithContent('catalog.csv', "Название;Цена;Остаток\nКуртка;1200;5\n")],
            ['Accept' => 'application/json'],
        );
        $analyze->assertOk();

        $import = $this->actingAs($user)->postJson(route('agents.catalog.import', $agent), [
            'token' => $analyze->json('token'),
            'mapping' => $analyze->json('mapping'),
        ]);
        $import->assertOk();

        // Knowledge ingestion: ExtractTextJob's real chain (-> ChunkTextJob
        // -> EmbedChunksJob), on the sync queue connection.
        $this->mock(LlmProviderInterface::class, function ($mock) {
            $mock->shouldReceive('estimateTokens')->andReturn(5);
            $mock->shouldReceive('embeddingModel')->andReturn('test-embedding-model');
            $mock->shouldReceive('embedBatch')->andReturnUsing(function (array $texts) {
                $result = [];
                foreach ($texts as $key => $text) {
                    $result[$key] = ['vector' => array_fill(0, 8, 0.125), 'input_tokens' => 5];
                }

                return $result;
            });
        });

        $path = "knowledge/{$agent->id}/sources/faq.txt";
        Storage::disk('local')->put($path, "Our store is open every day from 9am to 8pm.\n\n");

        $source = new KnowledgeSource([
            'type' => 'text',
            'name' => 'FAQ',
            'file_path' => $path,
            'mime_type' => 'text/plain',
            'status' => 'pending',
        ]);
        $source->agent_id = $agent->id;
        $source->save();

        ExtractTextJob::dispatch($source);

        $this->assertGreaterThan(0, $source->chunks()->count(), 'Knowledge ingestion did not produce any chunks.');

        // A BI source upload through the real job chain, on the sync queue
        // connection: NormalizeSourceJob -> MaterializeDatasetJob, through
        // SourceNormalizer's explicit-stamp bulk insert of BiColumn rows.
        $biSource = $this->csvSource($user, [
            ['Дата', 'Товар', 'Сумма'],
            ['01.03.2026', 'Куртка', '1500'],
        ]);
        NormalizeSourceJob::dispatch($biSource);
        $this->assertNotNull($biSource->fresh()->datasets()->first(), 'BI ingestion did not produce a dataset.');

        // Belt-and-suspenders spot checks before the full sweep: the two
        // explicit bulk-insert paths and the DealObserver side effect are
        // exactly the places most likely to regress silently.
        $this->assertDatabaseHas('deal_activities', ['deal_id' => $deal->id, 'team_id' => $user->current_team_id]);
        $this->assertSame(0, DB::table('dataset_products')->whereNull('team_id')->count());
        $this->assertSame(0, DB::table('bi_columns')->whereNull('team_id')->count());

        $this->artisan('team:verify-migration')
            ->expectsOutputToContain('Verification passed')
            ->assertExitCode(0);
    }
}
