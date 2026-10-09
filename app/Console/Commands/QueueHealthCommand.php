<?php

namespace App\Console\Commands;

use App\Models\KnowledgeSource;
use App\Services\Queue\WorkerHeartbeat;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One glance at whether the queue is actually working: is a worker alive,
 * what is it doing, is anything piling up or stuck.
 *
 * Exits non-zero when something is wrong, so it can be used as a check in a
 * script and not just read by eye.
 */
#[Signature('queue:health {--json : Output machine-readable JSON}')]
#[Description('Show queue worker health: heartbeat, backlog, failures and stuck knowledge sources')]
class QueueHealthCommand extends Command
{
    public function handle(WorkerHeartbeat $heartbeat): int
    {
        $report = $this->report($heartbeat);

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $report['healthy'] ? self::SUCCESS : self::FAILURE;
        }

        $this->newLine();

        foreach ($report['workers'] as $pool => $worker) {
            $this->components->twoColumnDetail("<fg=gray>Worker: {$pool}</>", $this->workerLine($worker));
        }

        $this->components->twoColumnDetail('<fg=gray>Pending jobs</>', (string) $report['queue']['pending']);
        $this->components->twoColumnDetail('<fg=gray>Reserved (in flight)</>', (string) $report['queue']['reserved']);
        $this->components->twoColumnDetail('<fg=gray>Failed (all time)</>', (string) $report['queue']['failed']);

        foreach ($report['queue']['by_queue'] as $queue => $pending) {
            $this->components->twoColumnDetail("<fg=gray>  pending on {$queue}</>", (string) $pending);
        }

        $this->components->twoColumnDetail('<fg=gray>Knowledge in progress</>', (string) $report['knowledge']['in_progress']);
        $this->components->twoColumnDetail('<fg=gray>Knowledge stuck</>', (string) $report['knowledge']['stuck']);
        $this->newLine();

        if ($report['healthy']) {
            $this->components->info('Queue looks healthy.');

            return self::SUCCESS;
        }

        foreach ($report['problems'] as $problem) {
            $this->components->error($problem);
        }

        $this->line('  <fg=gray>Start the supervised worker with:</> composer run dev  <fg=gray>(or)</> php artisan queue:supervise');
        $this->newLine();

        return self::FAILURE;
    }

    /**
     * @return array<string, mixed>
     */
    protected function report(WorkerHeartbeat $heartbeat): array
    {
        $problems = [];
        $workers = [];

        // Every pool is reported, and every pool's absence is its own problem: an
        // alive `default` worker says nothing about whether BI imports are being
        // picked up, and a health check that averaged the two would hide exactly
        // the outage it exists to catch.
        foreach (WorkerHeartbeat::pools() as $pool) {
            $poolBeat = WorkerHeartbeat::for($pool);
            $beat = $poolBeat->read();
            $stall = $poolBeat->stallReason();

            if ($beat === null) {
                $problems[] = "No [{$pool}] worker has checked in — nothing is processing that queue.";
            } elseif ($stall !== null) {
                $problems[] = "Worker [{$pool}] stalled: ".$stall;
            } elseif (! $poolBeat->running()) {
                $problems[] = "The [{$pool}] worker heartbeat is stale — that worker is not running.";
            }

            $workers[$pool] = [
                'state' => $beat['state'] ?? null,
                'pid' => $beat['pid'] ?? null,
                'job' => $beat['job'] ?? null,
                'seconds_since_heartbeat' => $beat === null ? null : $poolBeat->secondsSince($beat['at'] ?? null),
                'stall_reason' => $stall,
            ];
        }

        $stuckKnowledge = $this->stuckKnowledgeCount();

        if ($stuckKnowledge > 0) {
            $problems[] = "{$stuckKnowledge} knowledge source(s) stuck — run `php artisan queue:watchdog` to recover them.";
        }

        return [
            'healthy' => $problems === [],
            'problems' => $problems,
            'workers' => $workers,
            'queue' => [
                'pending' => DB::table('jobs')->whereNull('reserved_at')->count(),
                'reserved' => DB::table('jobs')->whereNotNull('reserved_at')->count(),
                'failed' => DB::table('failed_jobs')->count(),
                // Per queue, because "12 pending" tells an operator nothing about
                // WHICH pool is behind.
                'by_queue' => DB::table('jobs')
                    ->whereNull('reserved_at')
                    ->selectRaw('queue, count(*) as aggregate')
                    ->groupBy('queue')
                    ->pluck('aggregate', 'queue')
                    ->all(),
            ],
            'knowledge' => [
                'in_progress' => KnowledgeSource::whereIn('status', ['pending', 'processing'])->count(),
                'stuck' => $stuckKnowledge,
            ],
        ];
    }

    protected function stuckKnowledgeCount(): int
    {
        $pendingCutoff = now()->subSeconds((int) config('worker.stuck.pending_seconds', 180));
        $processingCutoff = now()->subSeconds((int) config('worker.stuck.processing_seconds', 420));

        return KnowledgeSource::query()
            ->where(fn ($q) => $q
                ->where(fn ($p) => $p->where('status', 'pending')->where('updated_at', '<', $pendingCutoff))
                ->orWhere(fn ($p) => $p->where('status', 'processing')->where('updated_at', '<', $processingCutoff))
            )
            ->count();
    }

    /**
     * @param  array<string, mixed>  $worker  One pool's heartbeat summary.
     */
    protected function workerLine(array $worker): string
    {
        if ($worker['state'] === null) {
            return '<fg=red>absent</>';
        }

        if ($worker['stall_reason'] !== null) {
            return '<fg=red>stalled</> <fg=gray>('.$worker['stall_reason'].')</>';
        }

        $suffix = $worker['state'] === WorkerHeartbeat::STATE_WORKING
            ? ' on '.class_basename((string) $worker['job'])
            : ", last beat {$worker['seconds_since_heartbeat']}s ago";

        return "<fg=green>{$worker['state']}</> <fg=gray>(pid {$worker['pid']}{$suffix})</>";
    }
}
