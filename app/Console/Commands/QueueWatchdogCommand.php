<?php

namespace App\Console\Commands;

use App\Jobs\ChunkTextJob;
use App\Jobs\EmbedChunksJob;
use App\Jobs\ExtractTextJob;
use App\Models\KnowledgeSource;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

#[Signature('queue:watchdog {--dry-run : Report what would be recovered without touching anything}')]
#[Description('Recover knowledge sources left stuck by a dead or hung queue worker')]
class QueueWatchdogCommand extends Command
{
    public function handle(): int
    {
        $stuck = $this->stuckSources();

        if ($stuck->isEmpty()) {
            $this->components->info('Nothing stuck.');

            return self::SUCCESS;
        }

        foreach ($stuck as $source) {
            $this->recover($source);
        }

        return self::SUCCESS;
    }

    protected function stuckSources()
    {
        $pendingCutoff = now()->subSeconds((int) config('worker.stuck.pending_seconds', 180));
        $processingCutoff = now()->subSeconds((int) config('worker.stuck.processing_seconds', 420));

        return KnowledgeSource::query()
            ->where(fn ($q) => $q
                ->where(fn ($p) => $p->where('status', 'pending')->where('updated_at', '<', $pendingCutoff))
                ->orWhere(fn ($p) => $p->where('status', 'processing')->where('updated_at', '<', $processingCutoff))
            )
            ->orderBy('id')
            ->get();
    }

    protected function recover(KnowledgeSource $source): void
    {

        if ($this->hasUnreservedJob($source)) {
            $this->line("  <fg=gray>#{$source->id} {$source->name}: job still queued, leaving it</>");

            return;
        }

        $attempts = (int) ($source->meta['recovery_attempts'] ?? 0);
        $max = (int) config('worker.stuck.max_recovery_attempts', 2);

        if ($attempts >= $max) {
            $this->giveUp($source, $attempts);

            return;
        }

        $job = $this->resumeJobFor($source);

        $this->components->warn(sprintf(
            'Recovering #%d %s (stuck in %s) — re-dispatching %s (attempt %d/%d)',
            $source->id, $source->name, $source->status, class_basename($job), $attempts + 1, $max
        ));

        if ($this->option('dry-run')) {
            return;
        }

        Log::warning('Watchdog recovered a stuck knowledge source', [
            'knowledge_source_id' => $source->id,
            'previous_status' => $source->status,
            'resume_job' => $job,
            'attempt' => $attempts + 1,
        ]);

        $source->update([
            'status' => 'pending',
            'error_message' => null,
            'meta' => array_merge($source->meta ?? [], [
                'recovery_attempts' => $attempts + 1,
                'last_recovered_at' => now()->toDateTimeString(),
            ]),
        ]);

        $job::dispatch($source);
    }

    protected function giveUp(KnowledgeSource $source, int $attempts): void
    {
        $this->components->error("Giving up on #{$source->id} {$source->name} after {$attempts} recovery attempts.");

        if ($this->option('dry-run')) {
            return;
        }

        $source->update([
            'status' => 'failed',
            'error_message' => "Processing stopped responding and could not be recovered automatically after {$attempts} attempts. Delete the source and add it again, or retry it from the knowledge panel.",
        ]);

        Log::error('Watchdog gave up on a stuck knowledge source', [
            'knowledge_source_id' => $source->id,
            'attempts' => $attempts,
        ]);
    }

    protected function resumeJobFor(KnowledgeSource $source): string
    {
        if ($source->chunks()->exists()) {
            return EmbedChunksJob::class;
        }

        if (Storage::disk('local')->exists($source->extractedPath())) {
            return ChunkTextJob::class;
        }

        return ExtractTextJob::class;
    }

    protected function hasUnreservedJob(KnowledgeSource $source): bool
    {
        return DB::table('jobs')
            ->whereNull('reserved_at')
            ->where('payload', 'like', '%KnowledgeSource%')
            ->where(fn ($q) => $q

                ->where('payload', 'like', '%\"id\";i:'.$source->id.';%')

                ->orWhere('payload', 'like', '%"id":'.$source->id.',%')
            )
            ->exists();
    }
}
