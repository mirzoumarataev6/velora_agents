<?php

namespace App\Providers;

use App\Models\BiDataset;
use App\Models\BiSource;
use App\Models\Deal;
use App\Models\Team;
use App\Models\User;
use App\Observers\BiDatasetObserver;
use App\Observers\BiSourceObserver;
use App\Observers\DealObserver;
use App\Observers\UserObserver;
use App\Services\Database\DriverManager;
use App\Services\Llm\LlmProviderContext;
use App\Services\Llm\LlmProviderFactory;
use App\Services\Llm\LlmProviderInterface;
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

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(VectorStoreInterface::class, MySqlCosineVectorStore::class);

        $this->app->singleton(DriverManager::class);

        $this->app->scoped(UsageRecorder::class);

        $this->app->scoped(LlmProviderContext::class);

        $this->app->bind(FeedbackCadence::class, fn () => FeedbackCadence::fromConfig());

        $this->app->bind(LlmProviderInterface::class, function ($app) {
            $team = $app->make(LlmProviderContext::class)->current();

            if ($team === null && auth()->check()) {
                $team = Team::find(auth()->user()->activeTeamId());
            }

            return $app->make(LlmProviderFactory::class)->make($team);
        });

        $this->app->useLangPath(resource_path('lang'));
    }

    public function boot(): void
    {
        $this->app->make(RegistersTeamPermissions::class)->register();

        Deal::observe(DealObserver::class);

        User::observe(UserObserver::class);
        BiSource::observe(BiSourceObserver::class);
        BiDataset::observe(BiDatasetObserver::class);

        $this->recordWorkerHeartbeat();
        $this->limitDatabaseConnectionTests();
        $this->limitBiUploads();
        $this->limitBiLayoutWrites();
    }

    protected function limitDatabaseConnectionTests(): void
    {
        RateLimiter::for('database-test', function (Request $request) {
            $perMinute = max(1, (int) config('agent.database.test_rate_limit', 10));

            return Limit::perMinute($perMinute)->by((string) ($request->user()?->id ?? $request->ip()));
        });
    }

    protected function limitBiUploads(): void
    {
        RateLimiter::for('bi-upload', function (Request $request) {
            $perMinute = max(1, (int) config('agent.bi.ingest.upload_rate_limit', 6));

            return Limit::perMinute($perMinute)->by((string) ($request->user()?->id ?? $request->ip()));
        });
    }

    protected function limitBiLayoutWrites(): void
    {
        RateLimiter::for('bi-layout', function (Request $request) {
            $perMinute = max(1, (int) config('agent.bi.query.layout_rate_limit', 60));

            return Limit::perMinute($perMinute)->by((string) ($request->user()?->id ?? $request->ip()));
        });
    }

    protected function recordWorkerHeartbeat(): void
    {
        Event::listen(Looping::class, fn () => $this->app->make(WorkerHeartbeat::class)->idle());

        Event::listen(JobProcessing::class, function (JobProcessing $event) {
            $timeout = method_exists($event->job, 'timeout') ? $event->job->timeout() : null;

            $this->app->make(WorkerHeartbeat::class)->working($event->job->resolveName(), $timeout);
        });

        foreach ([JobProcessed::class, JobFailed::class, JobExceptionOccurred::class] as $event) {
            Event::listen($event, fn () => $this->app->make(WorkerHeartbeat::class)->idle(force: true));
        }
    }
}
