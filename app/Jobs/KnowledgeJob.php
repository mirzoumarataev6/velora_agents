<?php

namespace App\Jobs;

use App\Exceptions\LlmException;
use App\Models\KnowledgeSource;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

abstract class KnowledgeJob implements ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public int $tries = 3;

    public int $timeout = 300;

    public KnowledgeSource $source;

    // Возвращает задержки в секундах перед повторными попытками выполнения задачи
    public function backoff(): array
    {
        return [10, 30];
    }

    // Отмечает источник знаний как неудавшийся, если задача провалилась окончательно
    public function failed(?Throwable $e): void
    {
        if (! isset($this->source) || ! $this->source->exists) {
            return;
        }

        $this->markFailed($this->source->fresh() ?? $this->source, $e ?? new \RuntimeException('The queue worker stopped before this step finished.'));
    }

    // Решает, повторить ли попытку при временной ошибке или окончательно пометить источник неудавшимся
    protected function handleFailure(KnowledgeSource $source, Throwable $e): void
    {
        if ($this->isTransient($e) && $this->attempts() < $this->tries) {
            Log::warning('Knowledge step hit a transient error — retrying', [
                'knowledge_source_id' => $source->id,
                'step' => static::class,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $this->markFailed($source, $e);
    }

    // Определяет, является ли ошибка временной и достойной повторной попытки
    protected function isTransient(Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        if ($e instanceof LlmException) {
            return $e->status === 0 || $e->status === 429 || $e->status >= 500;
        }

        return (bool) preg_grep(
            '/timed? ?out|connection|temporarily|rate limit|too many requests|deadlock|try again/i',
            [$e->getMessage()]
        );
    }

    // Помечает источник знаний неудавшимся и сохраняет текст ошибки
    protected function markFailed(KnowledgeSource $source, Throwable $e): void
    {
        Log::error('Knowledge source processing failed', [
            'knowledge_source_id' => $source->id,
            'step' => static::class,
            'error' => $e->getMessage(),
        ]);

        $source->update([
            'status' => 'failed',
            'error_message' => Str::limit($e->getMessage(), 1000),
        ]);
    }

    // Помечает источник знаний как обрабатываемый перед началом шага
    protected function beginStep(): void
    {
        $this->source->forceFill(['status' => 'processing', 'error_message' => null])->save();
    }
}
