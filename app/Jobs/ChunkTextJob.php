<?php

namespace App\Jobs;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeSource;
use App\Services\Knowledge\ChunkerService;
use App\Services\Llm\LlmProviderInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ChunkTextJob extends KnowledgeJob
{
    // Создаёт задачу разбиения извлечённого текста источника на чанки
    public function __construct(public KnowledgeSource $source) {}

    // Разбивает извлечённый текст источника на чанки и запускает их эмбеддинг
    public function handle(ChunkerService $chunker, LlmProviderInterface $llm): void
    {
        $this->beginStep();

        try {
            $path = $this->source->extractedPath();

            if (! Storage::disk('local')->exists($path)) {
                throw new RuntimeException('Extracted content is missing.');
            }

            $extracted = json_decode(Storage::disk('local')->get($path), true) ?? [];
            $chunkTexts = $chunker->chunk($extracted, config('agent.rag'));

            if ($chunkTexts === []) {
                throw new RuntimeException('No content could be extracted from this source.');
            }

            DB::transaction(function () use ($chunkTexts, $llm) {
                $this->source->chunks()->delete();

                foreach ($chunkTexts as $index => $text) {
                    $chunk = new KnowledgeChunk([
                        'content' => $text,
                        'token_count' => $llm->estimateTokens($text),
                        'chunk_index' => $index,
                    ]);
                    $chunk->knowledge_source_id = $this->source->id;
                    $chunk->agent_id = $this->source->agent_id;
                    $chunk->save();
                }
            });

            Storage::disk('local')->delete($path);
        } catch (Throwable $e) {
            $this->handleFailure($this->source, $e);

            return;
        }

        EmbedChunksJob::dispatch($this->source);
    }
}
