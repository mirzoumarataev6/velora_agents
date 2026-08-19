<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Queue worker supervision
    |--------------------------------------------------------------------------
    |
    | This dev environment has no Supervisor/systemd — Windows + OSPanel — so
    | `queue:supervise` plays that role: it runs ONE `queue:work` child and
    | restarts it when it dies, hangs, or the code on disk changes.
    |
    */
    'supervise' => [
        // Passed straight to the child `queue:work`.
        'tries' => env('WORKER_TRIES', 3),
        'backoff' => env('WORKER_BACKOFF', '10,30,60'),
        // The child exits on its own this often and is respawned, which caps
        // the damage any leak or stale-code drift can do.
        'max_time' => env('WORKER_MAX_TIME', 3600),
        // Per-job timeout handed to queue:work. On Windows this is NOT
        // enforced by the worker itself (it needs pcntl, which does not exist
        // here) — the stall detection below is what actually enforces it.
        'timeout' => env('WORKER_TIMEOUT', 300),
        'sleep' => env('WORKER_SLEEP', 3),

        // How long to wait before respawning after the child exits. Doubles on
        // each rapid consecutive crash, up to the max, so a hard crash loop
        // (bad config, DB down) doesn't spin the CPU or spawn processes in a
        // tight loop — the "Access is denied" failure mode on Windows.
        'restart_delay' => env('WORKER_RESTART_DELAY', 2),
        'max_restart_delay' => env('WORKER_MAX_RESTART_DELAY', 30),

        // Watch these paths and gracefully restart the worker when they change,
        // so a long-lived `queue:work` never serves stale code (the failure
        // `worker:selfcheck` exists to catch). Relative to the project root.
        'watch' => ['app', 'config', 'routes', 'database/migrations', 'bootstrap'],
        'watch_interval' => env('WORKER_WATCH_INTERVAL', 3),

        // How often the supervisor runs `queue:watchdog` in-process (seconds).
        // In-process on purpose: no scheduler process, no extra php.exe.
        'watchdog_interval' => env('WORKER_WATCHDOG_INTERVAL', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Heartbeat / stall detection
    |--------------------------------------------------------------------------
    |
    | The worker records what it is doing on every queue loop. Two different
    | questions are asked of that record, with two different thresholds:
    | an IDLE worker must check in constantly, while a worker legitimately
    | chewing on a slow job (embedding a large catalog) may go quiet for as
    | long as that job's own timeout allows.
    |
    */
    'heartbeat' => [
        'cache_key' => 'worker:heartbeat',
        // Don't write on every loop iteration — the cache store is the
        // database here, and an idle worker loops constantly.
        'throttle_seconds' => 5,
        // An idle worker loops every `sleep` seconds; anything beyond this
        // means the process is gone or wedged.
        'idle_stale_seconds' => env('WORKER_IDLE_STALE_SECONDS', 60),
        // Grace added to the running job's own timeout before calling it hung.
        'job_grace_seconds' => env('WORKER_JOB_GRACE_SECONDS', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Stuck knowledge-source recovery
    |--------------------------------------------------------------------------
    |
    | A source is "stuck" when it has sat in a non-terminal state longer than
    | any healthy run of that state could take. Two windows, because the two
    | states fail for different reasons: `pending` means nothing ever picked
    | the job up (dead worker), `processing` means a step started and never
    | finished (killed worker, hung HTTP call).
    |
    */
    'stuck' => [
        'pending_seconds' => env('WORKER_STUCK_PENDING_SECONDS', 180),
        // Must stay above the longest legitimate step run (job timeout, 300s)
        // or the watchdog would re-dispatch work that is still running.
        'processing_seconds' => env('WORKER_STUCK_PROCESSING_SECONDS', 420),
        // Recovery re-dispatches the pipeline from the furthest step that
        // still has its inputs. After this many attempts the source is marked
        // failed with a clear message instead of being retried forever.
        'max_recovery_attempts' => env('WORKER_MAX_RECOVERY_ATTEMPTS', 2),
    ],

];
