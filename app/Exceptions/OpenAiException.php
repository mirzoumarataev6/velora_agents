<?php

namespace App\Exceptions;

class OpenAiException extends LlmException
{
    public static function fromResponse(string $operation, int $status, string $body): static
    {
        return static::for('OpenAI', $operation, $status, $body);
    }

    public static function fromThrowable(string $operation, \Throwable $previous): static
    {
        return static::forThrowable('OpenAI', $operation, $previous);
    }
}
