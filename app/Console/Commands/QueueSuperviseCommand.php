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

/**
 * Supervisor for the queue workers, for environments that have no Supervisor:
 * Windows + OSPanel. Runs ONE `queue:work` child PER POOL and keeps each alive.
 *
 * There are two pools (see config/worker.php): `default` carries customer-facing
 * work, `ingest` carries BI file imports that legitimately take minutes. They are
 * separate processes precisely so a 100MB spreadsheet cannot put itself in front
 * of a customer waiting for a reply. Each child gets its own `--queue`, its own
 * timeout, and — via the WORKER_POOL environment variable — its own heartbeat key,
 * without which one idle worker would keep clearing the other's stall state.
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
    /** @var array<string, Process> pool => its running child */
    protected array $workers = [];

    /** @var array<string, int> pool => consecutive crash count, for the backoff */
    protected array $consecutiveFailures = [];

    protected float $lastWatchdogAt = 0;

    protected float $lastWatchCheckAt = 0;

    /** @var array<string, int> */
    protected array $watchedState = [];

    protected bool $stopping = false;

    public function handle(WorkerHeartbeat $heartbeat): int
    {
        $pools = $this->pools();

        if ($this->option('once')) {
            // --once is the test and one-shot path: it runs the DEFAULT pool only,
            // because "drain the queue and exit" has no meaning for two
            // independent pools running at different speeds.
            return $this->runWorkerProcess($pools[0])->wait();
        }

        $this->components->info('Supervising '.count($pools).' queue worker(s): '.implode(', ', $pools).'. Press Ctrl+C to stop.');
        $this->line('  <fg=gray>health:</> php artisan queue:health');
        $this->newLine();

        // Ctrl+C on Windows kills the console's whole process tree, but be
        // explicit — a supervisor that leaks orphaned workers would be worse
        // than no supervisor at all. Signal traps need pcntl, which Windows
        // does not have; the shutdown function covers it there.
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

            // Почему так: не ловить исключения тут означало, что переходная
            // ошибка БД (например, таблица cache на секунду отсутствует во
            // время migrate:fresh) убивала весь supervise-процесс целиком —
            // оба воркера молча умирали, и источники знаний зависали в
            // "pending" навсегда, потому что job просто не подхватывался.
            try {
                foreach ($pools as $pool) {
                    // Each pool is judged against its OWN heartbeat: a hung import
                    // must not be read as the customer-facing worker being hung, and
                    // vice versa.
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

    /**
     * Respawn the child if it exited; kill it if the heartbeat says it is hung.
     */
    protected function superviseChild(string $pool, WorkerHeartbeat $heartbeat): void
    {
        $worker = $this->workers[$pool] ?? null;

        if ($worker !== null && ! $worker->isRunning()) {
            $exitCode = $worker->getExitCode();

            // A clean exit is the normal `--max-time` recycle, not a failure,
            // so it must not feed the crash-loop backoff.
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

            // The job it died holding stays reserved until the queue's
            // retry_after elapses, then comes back on its own; anything it
            // left half-finished is picked up by the watchdog.
            $this->workers[$pool] = $this->runWorkerProcess($pool);
        }
    }

    /**
     * The pools to supervise, in declaration order — `default` first, so the
     * customer-facing worker is the one that starts before anything else.
     *
     * @return array<int, string>
     */
    protected function pools(): array
    {
        return WorkerHeartbeat::pools();
    }

    protected function runWorkerProcess(string $pool): Process
    {
        $config = config('worker.supervise');
        $poolConfig = (array) config("worker.pools.{$pool}", []);

        $queues = (string) ($poolConfig['queues'] ?? $pool);
        // A pool whose jobs are legitimately slower says so; everything else
        // inherits the shared timeout.
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
            // WORKER_POOL is what makes the child check in under its own
            // heartbeat key instead of trampling the other pool's — see
            // config/worker.php `pool` and WorkerHeartbeat.
            ['WORKER_POOL' => $pool],
            null,
            null // No process timeout: the worker is meant to run forever.
        );

        $process->start(function (string $type, string $buffer) use ($pool) {
            // Prefixed, because two workers writing to one console is otherwise
            // impossible to read.
            $this->output->write($this->prefixLines($pool, $buffer));
        });

        return $process;
    }

    /** Tag each line of a child's output with the pool it came from. */
    protected function prefixLines(string $pool, string $buffer): string
    {
        $trimmed = rtrim($buffer, "\r\n");

        if ($trimmed === '') {
            return $buffer;
        }

        $prefixed = preg_replace('/^/m', "<fg=gray>[{$pool}]</> ", $trimmed) ?? $trimmed;

        return $prefixed.PHP_EOL;
    }

    /**
     * Back off between restarts, doubling up to the configured ceiling. This
     * is what keeps a permanently-failing worker (bad DB credentials, say)
     * from spawning processes in a tight loop.
     */
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
