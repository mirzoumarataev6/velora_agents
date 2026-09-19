<?php

namespace App\Jobs;

use App\Models\KnowledgeSource;
use App\Services\Knowledge\KnowledgeExtractionService;
use App\Services\Llm\UsageRecorder;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ExtractTextJob extends KnowledgeJob
{
    // Создаёт задачу извлечения текста из источника знаний
    public function __construct(public KnowledgeSource $source) {}

    // Извлекает текст из источника знаний и запускает его разбиение на чанки
    public function handle(KnowledgeExtractionService $extractor, UsageRecorder $usage): void
    {
        $usage->for($this->source->agent);
        $this->beginStep();

        try {
            $extracted = $extractor->extract($this->source);

            Storage::disk('local')->makeDirectory(dirname($this->source->extractedPath()));
            Storage::disk('local')->put($this->source->extractedPath(), json_encode($extracted));

            if (! empty($extracted['meta'])) {
                $this->source->update([
                    'meta' => array_merge($this->source->meta ?? [], $extracted['meta']),
                ]);
            }
        } catch (Throwable $e) {
            $this->handleFailure($this->source, $e);

            return;
        }

        ChunkTextJob::dispatch($this->source);
    }
}
