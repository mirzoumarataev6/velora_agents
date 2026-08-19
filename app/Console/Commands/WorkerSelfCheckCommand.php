<?php

namespace App\Console\Commands;

use App\Jobs\VerifyWorkerCodeJob;
use App\Services\Queue\WorkerHeartbeat;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('worker:selfcheck')]
#[Description('Dispatch a canary job to prove whether the ACTIVE queue worker is running current code')]
class WorkerSelfCheckCommand extends Command
{
    public function handle(WorkerHeartbeat $heartbeat): int
    {
        // Is anyone even listening? Dispatching a canary onto a queue with no
        // worker looks identical to a stale worker, so say which it is.
        if (! $heartbeat->running()) {
            $this->components->error(
                $heartbeat->read() === null
                    ? 'No worker has checked in — nothing will pick this up. Start one with: composer run dev'
                    : 'The worker heartbeat is stale: '.($heartbeat->stallReason() ?? 'the worker is not running.')
            );
        }

        VerifyWorkerCodeJob::dispatch();

        $this->components->info('Dispatched. Whichever queue worker picks this up will log a "worker.selfcheck" entry.');
        $this->line('Check it with:');
        $this->line('  grep "worker.selfcheck" storage/logs/laravel.log | tail -1');
        $this->newLine();
        $this->line('If it says FAIL (or never appears within ~10s while a worker is running), that worker is stale.');
        $this->line('A supervised worker (composer run dev) restarts itself on code changes, so a stale one means the supervisor is not running.');
        $this->newLine();
        $this->line('  <fg=gray>full queue health:</> php artisan queue:health');

        return self::SUCCESS;
    }
}
