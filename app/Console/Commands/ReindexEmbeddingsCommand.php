<?php

namespace App\Console\Commands;

use App\Models\Agent;
use App\Services\Llm\LlmProviderInterface;
use App\Services\Llm\UsageRecorder;
use App\Services\Rag\MySqlCosineVectorStore;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

#[Signature('embeddings:reindex {--agent= : Only reindex this agent id}')]
#[Description('Re-embed every knowledge chunk under the currently configured LLM provider')]
class ReindexEmbeddingsCommand extends Command
{
    protected const BATCH = 100;

    public function handle(LlmProviderInterface $llm, UsageRecorder $usage): int
    {
        $provider = $llm->name();
        $dimensions = $llm->embeddingDimensions();

        $this->components->info(sprintf(
            'Reindexing embeddings with provider [%s]%s.',
            $provider,
            $dimensions ? " ({$dimensions} dims)" : ''
        ));

        $agents = $this->targetAgents();

        if ($agents === null) {
            return self::FAILURE;
        }

        if ($agents->isEmpty()) {
            $this->components->warn('No matching agents found.');

            return self::SUCCESS;
        }

        $grandTotal = 0;
        $grandFailed = 0;

        foreach ($agents as $agent) {
            [$done, $failed] = $this->reindexAgent($agent, $llm, $usage);
            $grandTotal += $done;
            $grandFailed += $failed;
        }

        $this->newLine();

        if ($grandFailed > 0) {
            $this->components->error("Reindex finished with {$grandFailed} failed chunk batch(es); {$grandTotal} chunks re-embedded.");

            return self::FAILURE;
        }

        $this->components->info("Reindex complete: {$grandTotal} chunks re-embedded across {$agents->count()} agent(s).");

        return self::SUCCESS;
    }

    protected function targetAgents(): ?Collection
    {
        $agentId = $this->option('agent');

        if ($agentId === null) {
            return Agent::query()->orderBy('id')->get();
        }

        $agent = Agent::find($agentId);

        if (! $agent) {
            $this->components->error("Agent [{$agentId}] not found.");

            return null;
        }

        return collect([$agent]);
    }

    protected function reindexAgent(Agent $agent, LlmProviderInterface $llm, UsageRecorder $usage): array
    {
        $total = $agent->knowledgeChunks()->count();

        if ($total === 0) {
            $this->line("  Agent #{$agent->id} {$agent->name}: no chunks, skipping.");

            return [0, 0];
        }

        $this->line("  Agent #{$agent->id} {$agent->name}: {$total} chunk(s)");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $usage->for($agent);

        $done = 0;
        $failed = 0;

        $agent->knowledgeChunks()
            ->with('knowledgeSource')
            ->orderBy('id')
            ->chunkById(self::BATCH, function ($chunks) use ($llm, &$done, &$failed, $bar) {
                try {

                    $embeddings = $llm->embedBatch(
                        $chunks->mapWithKeys(fn ($chunk) => [$chunk->id => $chunk->embeddingText()])->all()
                    );

                    foreach ($chunks as $chunk) {
                        $embedding = $embeddings[$chunk->id] ?? null;

                        if ($embedding === null) {
                            continue;
                        }

                        $chunk->update([
                            'embedding' => $embedding['vector'],
                            'embedding_model' => $llm->embeddingModel(),
                            'token_count' => $embedding['input_tokens'],
                        ]);
                        $done++;
                    }
                } catch (Throwable $e) {

                    $failed++;
                    $this->newLine();
                    $this->components->warn('Batch failed: '.$e->getMessage());
                }

                $bar->advance($chunks->count());
            });

        $bar->finish();
        $this->newLine();

        MySqlCosineVectorStore::forgetCache($agent->id);

        return [$done, $failed];
    }
}
