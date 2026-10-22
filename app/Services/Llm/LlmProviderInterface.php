<?php

namespace App\Services\Llm;

interface LlmProviderInterface
{
    public function name(): string;

    public function embeddingDimensions(): ?int;

    public function embeddingModel(): string;

    public function generate(string $systemInstruction, array $contents, array $options = []): array;

    public function extractFromImage(string $absolutePath, string $prompt, ?string $mimeType = null): array;

    public function embed(string $text): array;

    public function embedBatch(array $texts): array;

    public function estimateTokens(string $text): int;
}
