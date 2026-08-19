<?php

namespace App\Providers;

use App\Services\Llm\GeminiProvider;
use App\Services\Llm\LlmProviderInterface;
use App\Services\Llm\OpenAiProvider;
use App\Services\Llm\UsageRecorder;
use App\Services\Queue\WorkerHeartbeat;
use App\Services\Rag\MySqlCosineVectorStore;
use App\Services\Rag\VectorStoreInterface;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * @var array<string, class-string<LlmProviderInterface>>
     */
    protected const LLM_PROVIDERS = [
        'openai' => OpenAiProvider::class,
        'gemini' => GeminiProvider::class,
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(VectorStoreInterface::class, MySqlCosineVectorStore::class);

        // One usage context per request/job, reset between them.
        $this->app->scoped(UsageRecorder::class);

        $this->app->bind(LlmProviderInterface::class, function ($app) {
            $configured = (string) config('agent.llm_provider');

            $provider = self::LLM_PROVIDERS[$configured] ?? null;

            if ($provider === null) {
                throw new InvalidArgumentException(
                    "Unsupported agent.llm_provider [{$configured}]. Supported: "
                    .implode(', ', array_keys(self::LLM_PROVIDERS)).'.'
                );
            }

            return $app->make($provider);
        });

        $this->app->useLangPath(resource_path('lang'));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->recordWorkerHeartbeat();
    }

    /**
     * Let the queue worker publish what it is doing, so the supervisor and
     * `queue:health` can tell "alive and idle" from "hung inside a job" from
     * "gone". Only queue-worker processes emit these events, so this costs
     * nothing in web requests.
     */
    protected function recordWorkerHeartbeat(): void
    {
        Event::listen(Looping::class, fn () => $this->app->make(WorkerHeartbeat::class)->idle());

        Event::listen(JobProcessing::class, function (JobProcessing $event) {
            $timeout = method_exists($event->job, 'timeout') ? $event->job->timeout() : null;

            $this->app->make(WorkerHeartbeat::class)->working($event->job->resolveName(), $timeout);
        });

        // Both the success and failure paths return the worker to idle — a job
        // that threw is finished as far as liveness is concerned.
        foreach ([JobProcessed::class, JobFailed::class, JobExceptionOccurred::class] as $event) {
            Event::listen($event, fn () => $this->app->make(WorkerHeartbeat::class)->idle(force: true));
        }
    }
}
