<?php

namespace App\Services\Llm;

use App\Models\Team;

/**
 * Текущая команда, от которой LlmProviderFactory берёт ai_provider/ai_model —
 * тот же приём, что и у UsageRecorder::for()/scoped(), только контекст здесь
 * не для логирования, а для выбора самого провайдера. Scoped-синглтон: одно
 * значение на запрос/джобу, сбрасывается между ними.
 *
 * Почему так: LlmProviderInterface резолвится контейнером один раз в
 * конструкторе потребителя (AgentReplyService и т.п.), раньше, чем код,
 * знающий команду, успевает что-то сказать напрямую. Явно задать контекст
 * обязаны только фоновые пути (HandleIncomingMessageJob — агент почты
 * пользователя не виден) — веб-запросы получают активную команду
 * пользователя автоматически как запасной вариант в самой фабрике.
 */
class LlmProviderContext
{
    protected ?Team $team = null;

    public function for(?Team $team): static
    {
        $this->team = $team;

        return $this;
    }

    public function current(): ?Team
    {
        return $this->team;
    }
}
