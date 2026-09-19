<?php

namespace App\Jobs;

use App\Events\KnowledgeSourceProcessed;
use App\Models\KnowledgeSource;
use App\Services\Llm\LlmProviderInterface;
use App\Services\Llm\UsageRecorder;
use App\Services\Rag\MySqlCosineVectorStore;
use RuntimeException;
use Throwable;

class EmbedChunksJob extends KnowledgeJob
{
    // Создаёт задачу построения эмбеддингов для чанков источника знаний
    public function __construct(public KnowledgeSource $source) {}

    // Строит эмбеддинги для необработанных чанков и переводит источник в статус "готов"
    public function handle(LlmProviderInterface $llm, UsageRecorder $usage): void
    {
        $usage->for($this->source->agent);
        $this->beginStep();

        try {
            $pending = $this->source->chunks()->whereNull('embedding')->orderBy('chunk_index')->get();

            if ($pending->isEmpty() && $this->source->chunks()->count() === 0) {
                throw new RuntimeException('No chunks were created for this source.');
            }

            if ($pending->isNotEmpty()) {
                $pending->load('knowledgeSource');
                $embeddings = $llm->embedBatch(
                    $pending->mapWithKeys(fn ($chunk) => [$chunk->id => $chunk->embeddingText()])->all()
                );

                foreach ($pending as $chunk) {
                    $embedding = $embeddings[$chunk->id] ?? null;

                    if ($embedding === null) {
                        continue;
                    }

                    $chunk->update([
                        'embedding' => $embedding['vector'],
                        'embedding_model' => $llm->embeddingModel(),
                        'token_count' => $embedding['input_tokens'],
                    ]);
                }
            }

            $this->source->update(['status' => 'ready', 'error_message' => null]);
            MySqlCosineVectorStore::forgetCache($this->source->agent_id);
            KnowledgeSourceProcessed::dispatch($this->source);
        } catch (Throwable $e) {
            $this->handleFailure($this->source, $e);
        }
    }
}
