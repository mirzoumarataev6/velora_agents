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
        $this->components->twoColumnDetail('<fg=gray>Worker</>', $this->workerLine($report));
        $this->components->twoColumnDetail('<fg=gray>Pending jobs</>', (string) $report['queue']['pending']);
        $this->components->twoColumnDetail('<fg=gray>Reserved (in flight)</>', (string) $report['queue']['reserved']);
        $this->components->twoColumnDetail('<fg=gray>Failed (all time)</>', (string) $report['queue']['failed']);
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
        $beat = $heartbeat->read();
        $stall = $heartbeat->stallReason();
        $problems = [];

        if ($beat === null) {
            $problems[] = 'No worker has checked in — nothing is processing the queue.';
        } elseif ($stall !== null) {
            $problems[] = 'Worker stalled: '.$stall;
        } elseif (! $heartbeat->running()) {
            $problems[] = 'The worker heartbeat is stale — the worker is not running.';
        }

        $stuckKnowledge = $this->stuckKnowledgeCount();

        if ($stuckKnowledge > 0) {
            $problems[] = "{$stuckKnowledge} knowledge source(s) stuck — run `php artisan queue:watchdog` to recover them.";
        }

        return [
            'healthy' => $problems === [],
            'problems' => $problems,
            'worker' => [
                'state' => $beat['state'] ?? null,
                'pid' => $beat['pid'] ?? null,
                'job' => $beat['job'] ?? null,
                'seconds_since_heartbeat' => $beat === null ? null : $heartbeat->secondsSince($beat['at'] ?? null),
                'stall_reason' => $stall,
            ],
            'queue' => [
                'pending' => DB::table('jobs')->whereNull('reserved_at')->count(),
                'reserved' => DB::table('jobs')->whereNotNull('reserved_at')->count(),
                'failed' => DB::table('failed_jobs')->count(),
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

    protected function workerLine(array $report): string
    {
        $worker = $report['worker'];

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
