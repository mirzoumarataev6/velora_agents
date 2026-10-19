<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Какой провайдер LLM использует команда и его модель/base_url.
     *
     * Почему так: это tenant-данные (как и всё остальное во вкладке Team),
     * а не глобальный .env — каждая команда может держать свой локальный
     * Ollama или ключ другого провайдера. NULL во всех трёх колонках значит
     * "не настроено", и тогда LlmProviderFactory берёт глобальный дефолт из
     * config('agent.llm_provider') — ни одна существующая команда не ломается
     * этой миграцией.
     */
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->string('ai_provider', 20)->nullable()->after('is_personal');
            $table->string('ai_model', 100)->nullable()->after('ai_provider');
            $table->string('ollama_base_url', 255)->nullable()->after('ai_model');
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn(['ai_provider', 'ai_model', 'ollama_base_url']);
        });
    }
};
