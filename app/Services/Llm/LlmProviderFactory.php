<?php

namespace App\Services\Llm;

use App\Exceptions\LlmException;
use App\Models\Team;
use InvalidArgumentException;

/**
 * Строит LlmProviderInterface для конкретной команды (или глобальный дефолт,
 * если команда ничего не выбрала) — единственное место, которое знает про
 * все три провайдера сразу.
 *
 * Почему так: провайдеры читают свою конфигурацию из config('agent.*') в
 * конструкторе, а команда может переопределить только модель и (для Ollama)
 * base_url. Вместо того чтобы переписывать три конструктора под явный массив
 * настроек, фабрика на время подменяет нужный config()-раздел, резолвит
 * провайдер через контейнер и возвращает раздел обратно — дешевле и не
 * трогает ни один из существующих провайдеров.
 */
class LlmProviderFactory
{
    /** @var array<string, class-string<LlmProviderInterface>> */
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

    /**
     * Пробный вызов для кнопки "Test connection" на странице настроек —
     * строит провайдер из ЕЩЁ НЕ сохранённых полей формы и делает короткий
     * запрос, не трогая ничего в базе.
     *
     * @param  array{ai_model?: ?string, ollama_base_url?: ?string}  $settings
     * @return array{ok: bool, error: ?string}
     */
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

    /**
     * @return array<string, mixed>
     */
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

    /**
     * @param  array<string, mixed>  $overrides
     */
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
