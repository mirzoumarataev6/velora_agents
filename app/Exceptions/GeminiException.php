<?php

namespace App\Exceptions;

class GeminiException extends LlmException
{
    public static function fromResponse(string $operation, int $status, string $body): static
    {
        return static::for('Gemini', $operation, $status, $body);
    }

    public static function fromThrowable(string $operation, \Throwable $previous): static
    {
        return static::forThrowable('Gemini', $operation, $previous);
    }
}
