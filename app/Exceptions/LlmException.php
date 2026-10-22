<?php

namespace App\Exceptions;

use Exception;

class LlmException extends Exception
{
    public int $status = 0;

    public static function for(string $provider, string $operation, int $status, string $body): static
    {
        $exception = new static("{$provider} API [{$operation}] failed with status {$status}: {$body}");
        $exception->status = $status;

        return $exception;
    }

    public static function forThrowable(string $provider, string $operation, \Throwable $previous): static
    {
        return new static("{$provider} API [{$operation}] request failed: {$previous->getMessage()}", 0, $previous);
    }
}
