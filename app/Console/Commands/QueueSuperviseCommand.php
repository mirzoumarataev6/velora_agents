<?php

namespace App\Console\Commands;

use App\Services\Queue\WorkerHeartbeat;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Throwable;

#[Signature('queue:supervise {--once : Run the worker once and exit instead of supervising} {--no-watch : Do not restart the worker when project files change}')]
#[Description('Run the queue worker under supervision: restart it if it dies, hangs, or the code changes')]
class QueueSuperviseCommand extends Command
{
    protected array $workers = [];

    protected array $consecutiveFailures = [];

    protected float $lastWatchdogAt = 0;

    protected float $lastWatchCheckAt = 0;

    protected array $watchedState = [];

    protected bool $stopping = false;

    public function handle(WorkerHeartbeat $heartbeat): int
    {
        $pools = $this->pools();

        if ($this->option('once')) {

            return $this->runWorkerProcess($pools[0])->wait();
        }

        $this->components->info('Supervising '.count($pools).' queue worker(s): '.implode(', ', $pools).'. Press Ctrl+C to stop.');
        $this->line('  <fg=gray>health:</> php artisan queue:health');
        $this->newLine();

        if (defined('SIGINT') && defined('SIGTERM')) {
            $this->trap([SIGINT, SIGTERM], fn () => $this->stopping = true);
        }

        register_shutdown_function(fn () => $this->stopWorkers());

        $this->watchedState = $this->watchedFingerprint();

        foreach ($pools as $pool) {
            $this->workers[$pool] = $this->runWorkerProcess($pool);
        }

        while (! $this->stopping) {
            usleep(500_000);

            try {
                foreach ($pools as $pool) {

                    $this->superviseChild($pool, WorkerHeartbeat::for($pool));
                }

                $this->restartOnCodeChange();
                $this->runWatchdogPeriodically();
            } catch (Throwable $e) {
                Log::error('queue:supervise tick failed, continuing', [
                    'exception' => $e,
                ]);
                $this->components->error('Supervisor tick failed: '.$e->getMessage().' — continuing.');
            }
        }

        $this->stopWorkers();
        $this->components->info('Supervisor stopped.');

        return self::SUCCESS;
    }

    protected function superviseChild(string $pool, WorkerHeartbeat $heartbeat): void
    {
        $worker = $this->workers[$pool] ?? null;

        if ($worker !== null && ! $worker->isRunning()) {
            $exitCode = $worker->getExitCode();

            if ($exitCode === 0) {
                $this->consecutiveFailures[$pool] = 0;
                $this->components->info("[{$pool}] Worker finished its shift — starting a fresh one.");
            } else {
                $failures = ($this->consecutiveFailures[$pool] ?? 0) + 1;
                $this->consecutiveFailures[$pool] = $failures;
                $this->components->error("[{$pool}] Worker exited with code {$exitCode} — restarting (failure #{$failures}).");
                $this->pauseBeforeRestart($pool);
            }

            $this->workers[$pool] = $this->runWorkerProcess($pool);

            return;
        }

        if (($reason = $heartbeat->stallReason()) !== null) {
            $this->components->error("[{$pool}] Worker stalled: {$reason}. Killing and restarting it.");
            $this->stopWorker($pool);
            $heartbeat->clear();

            $this->workers[$pool] = $this->runWorkerProcess($pool);
        }
    }

    protected function pools(): array
    {
        return WorkerHeartbeat::pools();
    }

    protected function runWorkerProcess(string $pool): Process
    {
        $config = config('worker.supervise');
        $poolConfig = (array) config("worker.pools.{$pool}", []);

        $queues = (string) ($poolConfig['queues'] ?? $pool);

        $timeout = (int) ($poolConfig['timeout'] ?? $config['timeout']);

        $process = new Process(
            [
                PHP_BINARY, 'artisan', 'queue:work',
                '--queue='.$queues,
                '--tries='.$config['tries'],
                '--backoff='.$config['backoff'],
                '--max-time='.$config['max_time'],
                '--timeout='.$timeout,
                '--sleep='.$config['sleep'],
            ],
            base_path(),

            ['WORKER_POOL' => $pool],
            null,
            null
        );

        $process->start(function (string $type, string $buffer) use ($pool) {

            $this->output->write($this->prefixLines($pool, $buffer));
        });

        return $process;
    }

    protected function prefixLines(string $pool, string $buffer): string
    {
        $trimmed = rtrim($buffer, "\r\n");

        if ($trimmed === '') {
            return $buffer;
        }

        $prefixed = preg_replace('/^/m', "<fg=gray>[{$pool}]</> ", $trimmed) ?? $trimmed;

        return $prefixed.PHP_EOL;
    }

    protected function pauseBeforeRestart(string $pool): void
    {
        $failures = max(1, $this->consecutiveFailures[$pool] ?? 1);

        $delay = min(
            (int) config('worker.supervise.restart_delay', 2) * (2 ** ($failures - 1)),
            (int) config('worker.supervise.max_restart_delay', 30)
        );

        if ($delay > 0) {
            $this->line("  <fg=gray>[{$pool}] waiting {$delay}s before restart</>");
            sleep($delay);
        }
    }

    protected function stopWorker(string $pool): void
    {
        if (($this->workers[$pool] ?? null)?->isRunning()) {
            $this->workers[$pool]->stop(10);
        }
    }

    protected function stopWorkers(): void
    {
        foreach (array_keys($this->workers) as $pool) {
            $this->stopWorker($pool);
        }
    }

    protected function restartOnCodeChange(): void
    {
        if ($this->option('no-watch')) {
            return;
        }

        $interval = (int) config('worker.supervise.watch_interval', 3);

        if (microtime(true) - $this->lastWatchCheckAt < $interval) {
            return;
        }

        $this->lastWatchCheckAt = microtime(true);
        $fingerprint = $this->watchedFingerprint();

        if ($fingerprint === $this->watchedState) {
            return;
        }

        $this->watchedState = $fingerprint;
        $this->components->info('Code changed — restarting the worker so it picks it up.');

        Artisan::call('queue:restart');
    }

    protected function watchedFingerprint(): array
    {
        $fingerprint = [];

        foreach ((array) config('worker.supervise.watch', []) as $path) {
            $absolute = base_path($path);

            if (! is_dir($absolute)) {
                continue;
            }

            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($files as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $fingerprint[$file->getPathname()] = $file->getMTime();
                }
            }
        }

        return $fingerprint;
    }

    protected function runWatchdogPeriodically(): void
    {
        $interval = (int) config('worker.supervise.watchdog_interval', 60);

        if (microtime(true) - $this->lastWatchdogAt < $interval) {
            return;
        }

        $this->lastWatchdogAt = microtime(true);

        try {
            Artisan::call('queue:watchdog', [], $this->output->getOutput());
        } catch (Throwable $e) {

            $this->components->warn('Watchdog run failed: '.$e->getMessage());
        }
    }
}
