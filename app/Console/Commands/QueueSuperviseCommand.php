<?php

namespace App\Console\Commands;

use App\Services\Queue\WorkerHeartbeat;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;

/**
 * Supervisor for the queue worker, for environments that have no Supervisor:
 * Windows + OSPanel. Runs exactly ONE `queue:work` child and keeps it alive.
 *
 * Why a single long-lived child rather than the old `queue:listen`:
 * `queue:listen` boots a brand new php.exe for every single job, which is
 * both slow and the direct cause of the "Access is denied" (proc_open)
 * failures seen here when several jobs land at once. `queue:work` boots once;
 * this command adds back the two things `queue:listen` was being used for —
 * restart-on-death and picking up code changes.
 *
 * What it recovers from:
 *   - the worker process dying (crash, OOM, killed by hand) → respawn, with a
 *     doubling delay so a hard crash loop can't spin;
 *   - a job hanging forever → detected via the heartbeat and killed, because
 *     `queue:work --timeout` is a no-op on Windows (no pcntl extension);
 *   - stale code in a long-lived worker → a graceful `queue:restart` when a
 *     watched path changes;
 *   - work stuck in the database → runs `queue:watchdog` on an interval,
 *     in-process, so no scheduler and no extra php.exe is needed.
 */
#[Signature('queue:supervise {--once : Run the worker once and exit instead of supervising} {--no-watch : Do not restart the worker when project files change}')]
#[Description('Run the queue worker under supervision: restart it if it dies, hangs, or the code changes')]
class QueueSuperviseCommand extends Command
{
    protected ?Process $worker = null;

    protected int $consecutiveFailures = 0;

    protected float $lastWatchdogAt = 0;

    protected float $lastWatchCheckAt = 0;

    /** @var array<string, int> */
    protected array $watchedState = [];

    protected bool $stopping = false;

    public function handle(WorkerHeartbeat $heartbeat): int
    {
        if ($this->option('once')) {
            return $this->runWorkerProcess()->wait();
        }

        $this->components->info('Supervising the queue worker. Press Ctrl+C to stop.');
        $this->line('  <fg=gray>health:</> php artisan queue:health');
        $this->newLine();

        // Ctrl+C on Windows kills the console's whole process tree, but be
        // explicit — a supervisor that leaks orphaned workers would be worse
        // than no supervisor at all. Signal traps need pcntl, which Windows
        // does not have; the shutdown function covers it there.
        if (defined('SIGINT') && defined('SIGTERM')) {
            $this->trap([SIGINT, SIGTERM], fn () => $this->stopping = true);
        }

        register_shutdown_function(fn () => $this->stopWorker());

        $this->watchedState = $this->watchedFingerprint();
        $this->worker = $this->runWorkerProcess();

        while (! $this->stopping) {
            usleep(500_000);

            $this->superviseChild($heartbeat);
            $this->restartOnCodeChange();
            $this->runWatchdogPeriodically();
        }

        $this->stopWorker();
        $this->components->info('Supervisor stopped.');

        return self::SUCCESS;
    }

    /**
     * Respawn the child if it exited; kill it if the heartbeat says it is hung.
     */
    protected function superviseChild(WorkerHeartbeat $heartbeat): void
    {
        if ($this->worker !== null && ! $this->worker->isRunning()) {
            $exitCode = $this->worker->getExitCode();

            // A clean exit is the normal `--max-time` recycle, not a failure,
            // so it must not feed the crash-loop backoff.
            if ($exitCode === 0) {
                $this->consecutiveFailures = 0;
                $this->components->info('Worker finished its shift — starting a fresh one.');
            } else {
                $this->consecutiveFailures++;
                $this->components->error("Worker exited with code {$exitCode} — restarting (failure #{$this->consecutiveFailures}).");
                $this->pauseBeforeRestart();
            }

            $this->worker = $this->runWorkerProcess();

            return;
        }

        if (($reason = $heartbeat->stallReason()) !== null) {
            $this->components->error("Worker stalled: {$reason}. Killing and restarting it.");
            $this->stopWorker();
            $heartbeat->clear();

            // The job it died holding stays reserved until the queue's
            // retry_after elapses, then comes back on its own; anything it
            // left half-finished is picked up by the watchdog.
            $this->worker = $this->runWorkerProcess();
        }
    }

    protected function runWorkerProcess(): Process
    {
        $config = config('worker.supervise');

        $process = new Process(
            [
                PHP_BINARY, 'artisan', 'queue:work',
                '--tries='.$config['tries'],
                '--backoff='.$config['backoff'],
                '--max-time='.$config['max_time'],
                '--timeout='.$config['timeout'],
                '--sleep='.$config['sleep'],
            ],
            base_path(),
            null,
            null,
            null // No process timeout: the worker is meant to run forever.
        );

        $process->start(function (string $type, string $buffer) {
            $this->output->write($buffer);
        });

        return $process;
    }

    /**
     * Back off between restarts, doubling up to the configured ceiling. This
     * is what keeps a permanently-failing worker (bad DB credentials, say)
     * from spawning processes in a tight loop.
     */
    protected function pauseBeforeRestart(): void
    {
        $delay = min(
            (int) config('worker.supervise.restart_delay', 2) * (2 ** ($this->consecutiveFailures - 1)),
            (int) config('worker.supervise.max_restart_delay', 30)
        );

        if ($delay > 0) {
            $this->line("  <fg=gray>waiting {$delay}s before restart</>");
            sleep($delay);
        }
    }

    protected function stopWorker(): void
    {
        if ($this->worker?->isRunning()) {
            $this->worker->stop(10);
        }
    }

    /**
     * A `queue:work` process loads each class once and then keeps serving that
     * copy — silently, with no error — until it is restarted. Watch the source
     * tree and ask the worker to finish its current job and exit whenever
     * anything changes.
     */
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

        // Graceful: the worker finishes the job in hand, then exits, and the
        // loop above respawns it. Nothing is killed, so nothing is lost.
        Artisan::call('queue:restart');
    }

    /**
     * @return array<string, int>
     */
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

    /**
     * Run the stuck-work watchdog in-process, on an interval. Deliberately not
     * a scheduled command: `schedule:work` would mean another permanent
     * php.exe, and process count is exactly what hurts on this setup.
     */
    protected function runWatchdogPeriodically(): void
    {
        $interval = (int) config('worker.supervise.watchdog_interval', 60);

        if (microtime(true) - $this->lastWatchdogAt < $interval) {
            return;
        }

        $this->lastWatchdogAt = microtime(true);

        try {
            Artisan::call('queue:watchdog', [], $this->output->getOutput());
        } catch (\Throwable $e) {
            // The watchdog is a safety net; if it throws (DB down, say) the
            // supervisor must keep supervising regardless.
            $this->components->warn('Watchdog run failed: '.$e->getMessage());
        }
    }
}
