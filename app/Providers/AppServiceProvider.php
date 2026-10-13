<?php

namespace App\Providers;

use App\Models\BiDataset;
use App\Models\BiSource;
use App\Models\Deal;
use App\Models\User;
use App\Observers\BiDatasetObserver;
use App\Observers\BiSourceObserver;
use App\Observers\DealObserver;
use App\Observers\UserObserver;
use App\Services\Database\DriverManager;
use App\Services\Llm\GeminiProvider;
use App\Services\Llm\LlmProviderInterface;
use App\Services\Llm\OpenAiProvider;
use App\Services\Llm\UsageRecorder;
use App\Services\Permissions\RegistersTeamPermissions;
use App\Services\Quality\FeedbackCadence;
use App\Services\Queue\WorkerHeartbeat;
use App\Services\Rag\MySqlCosineVectorStore;
use App\Services\Rag\VectorStoreInterface;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
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

        // Singleton so a driver registered at runtime (a test double, or a new
        // engine registered by a future provider) is visible to everything that
        // resolves the manager.
        $this->app->singleton(DriverManager::class);

        // One usage context per request/job, reset between them.
        $this->app->scoped(UsageRecorder::class);

        // Built from config rather than autowired: its two thresholds ARE the
        // cadence, and a test that changes them wants the change to take.
        $this->app->bind(FeedbackCadence::class, fn () => FeedbackCadence::fromConfig());

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
        $this->app->make(RegistersTeamPermissions::class)->register();

        Deal::observe(DealObserver::class);

        // The BI module stores each dataset as a real table plus a directory of
        // shards, neither of which a SQL cascade can remove. These three observers
        // are the chain that makes "delete" mean it — see UserObserver for why
        // account deletion in particular depends on it.
        User::observe(UserObserver::class);
        BiSource::observe(BiSourceObserver::class);
        BiDataset::observe(BiDatasetObserver::class);

        $this->recordWorkerHeartbeat();
        $this->limitDatabaseConnectionTests();
        $this->limitBiUploads();
        $this->limitBiLayoutWrites();
    }

    /**
     * "Test connection" opens a TCP connection to a host and port the caller
     * chose, and its error messages distinguish "refused" from "wrong password".
     * Unthrottled, that is a port scanner with a friendly UI pointed at anywhere
     * on the internet from our IP. Per user, not per IP: the endpoint is behind
     * auth, so the account is the thing worth limiting.
     */
    protected function limitDatabaseConnectionTests(): void
    {
        RateLimiter::for('database-test', function (Request $request) {
            $perMinute = max(1, (int) config('agent.database.test_rate_limit', 10));

            return Limit::perMinute($perMinute)->by((string) ($request->user()?->id ?? $request->ip()));
        });
    }

    /**
     * A BI upload stages a file of up to 100MB on our disk and queues minutes of
     * worker time on the `ingest` queue. Unthrottled, one account could fill the
     * disk and monopolize that worker with a held-down button — so the limit is
     * low and per account, which is the thing worth limiting behind auth.
     */
    protected function limitBiUploads(): void
    {
        RateLimiter::for('bi-upload', function (Request $request) {
            $perMinute = max(1, (int) config('agent.bi.ingest.upload_rate_limit', 6));

            return Limit::perMinute($perMinute)->by((string) ($request->user()?->id ?? $request->ip()));
        });
    }

    /**
     * A board drag writes every card's slot at once.
     *
     * Gridstack fires a change event on every drop, and a held-down resize can
     * produce a burst of them — so without a limiter one gesture can become a
     * write per animation frame. The ceiling is far higher than the upload
     * limiter's because the operation is cheap (a handful of small UPDATEs, and
     * LayoutUpdater skips the rows that did not move); it exists to bound a
     * runaway client rather than to ration anything.
     */
    protected function limitBiLayoutWrites(): void
    {
        RateLimiter::for('bi-layout', function (Request $request) {
            $perMinute = max(1, (int) config('agent.bi.query.layout_rate_limit', 60));

            return Limit::perMinute($perMinute)->by((string) ($request->user()?->id ?? $request->ip()));
        });
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
