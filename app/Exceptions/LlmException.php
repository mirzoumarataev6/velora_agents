<?php

namespace App\Exceptions;

use Exception;

/**
 * Base failure type for every LLM provider. Application code catches THIS
 * rather than a vendor-specific subclass, so swapping providers doesn't
 * change which failures degrade gracefully.
 */
class LlmException extends Exception
{
    /**
     * HTTP status the provider replied with, or 0 when the request never got
     * an answer at all (connection refused, DNS, timeout). Callers use this to
     * tell a retryable failure (0, 429, 5xx) from a permanent one (4xx) —
     * see KnowledgeJob::isTransient().
     */
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
