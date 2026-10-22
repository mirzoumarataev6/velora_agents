<?php

namespace App\Services\Llm;

use App\Exceptions\LlmException;
use App\Models\Team;
use InvalidArgumentException;

class LlmProviderFactory
{
    public const PROVIDERS = [
        'openai' => OpenAiProvider::class,
        'gemini' => GeminiProvider::class,
        'ollama' => OllamaProvider::class,
    ];

    public function make(?Team $team = null): LlmProviderInterface
    {
        $providerName = $this->resolveProviderName($team);
        $overrides = $this->overridesFor($providerName, $team);

        return $this->build($providerName, $overrides);
    }

    public function testConnection(string $providerName, array $settings): array
    {
        if (! array_key_exists($providerName, self::PROVIDERS)) {
            return ['ok' => false, 'error' => "Unsupported provider: {$providerName}"];
        }

        $overrides = array_filter([
            'chat_model' => $settings['ai_model'] ?? null,
            'base_url' => $providerName === 'ollama' ? ($settings['ollama_base_url'] ?? null) : null,
        ], fn ($value) => filled($value));

        try {
            $provider = $this->build($providerName, $overrides);

            $provider->generate(
                'You are a connectivity check.',
                [['role' => 'user', 'text' => 'Reply with the single word: ok']],
                ['max_output_tokens' => 10],
            );

            return ['ok' => true, 'error' => null];
        } catch (LlmException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    protected function resolveProviderName(?Team $team): string
    {
        return $team?->ai_provider ?: (string) config('agent.llm_provider');
    }

    protected function overridesFor(string $providerName, ?Team $team): array
    {
        if ($team === null) {
            return [];
        }

        return array_filter([
            'chat_model' => $team->ai_model,
            'base_url' => $providerName === 'ollama' ? $team->ollama_base_url : null,
        ], fn ($value) => filled($value));
    }

    protected function build(string $providerName, array $overrides): LlmProviderInterface
    {
        $class = self::PROVIDERS[$providerName] ?? null;

        if ($class === null) {
            throw new InvalidArgumentException(
                "Unsupported agent.llm_provider [{$providerName}]. Supported: "
                .implode(', ', array_keys(self::PROVIDERS)).'.'
            );
        }

        if ($overrides === []) {
            return app($class);
        }

        $key = "agent.{$providerName}";
        $original = config($key);

        config([$key => array_merge($original, $overrides)]);

        try {
            return app()->make($class);
        } finally {
            config([$key => $original]);
        }
    }
}
