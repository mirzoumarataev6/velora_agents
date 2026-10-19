<?php

namespace App\Exceptions;

class OllamaException extends LlmException
{
    public static function fromResponse(string $operation, int $status, string $body): static
    {
        return static::for('Ollama', $operation, $status, $body);
    }

    public static function fromThrowable(string $operation, \Throwable $previous): static
    {
        return static::forThrowable('Ollama', $operation, $previous);
    }

    /**
     * Сервер Ollama недоступен по сети (не тот же самый, что HTTP-ошибка от
     * самого Ollama) — отдельное сообщение, чтобы "Test connection" могло
     * прямо сказать "сервер недоступен", а не показать сырой текст исключения.
     */
    public static function unreachable(string $baseUrl, \Throwable $previous): static
    {
        $exception = new static("Ollama is unreachable at {$baseUrl}: {$previous->getMessage()}", 0, $previous);
        $exception->status = 0;

        return $exception;
    }

    /**
     * 404 с телом вида {"error":"model \"x\" not found, try pulling it first"}.
     */
    public static function modelNotFound(string $model): static
    {
        $exception = new static("Ollama model \"{$model}\" was not found. Pull it first with `ollama pull {$model}`.");
        $exception->status = 404;

        return $exception;
    }
}
