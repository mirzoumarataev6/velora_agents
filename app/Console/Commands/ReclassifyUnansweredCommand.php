<?php

namespace App\Console\Commands;

use App\Models\Message;
use App\Services\Quality\NonAnswerDetector;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One-off backfill for rows the old unanswered rule got wrong.
 *
 * The bug it cleans up after: `classifyUnanswered()` used to treat a weak
 * VECTOR-STORE score as proof that the customer went away empty-handed. The
 * knowledge base is only one of the agent's sources — the owner's address and
 * opening hours live in agent settings and reach the model through the system
 * prompt, never through retrieval — so every correct answer sourced from
 * anywhere else was recorded as "нет в базе знаний". The classifier now asks
 * the reply instead, but the rows written before that are still on disk, and
 * `meta` is only ever written at reply time. Nothing reprocesses them; this
 * does.
 *
 * WHAT IT RE-JUDGES, and nothing else: `knowledge_miss`. That is the only
 * branch the fix changed. `catalog_miss` is a fact about searches the model
 * actually ran and `provider_down` is a fact about an outage — both are as true
 * today as when they were written, and clearing them would delete real
 * findings.
 *
 * The verdict comes from NonAnswerDetector, the same object the live pipeline
 * consults, so a row kept here is a row today's classifier would also flag.
 * Everything is read from stored `meta` and the stored reply text: no LLM call,
 * no embedding, no retrieval, and therefore no cost and no chance of a
 * different model answering differently this time.
 *
 * Safe to re-run. A row written after the fix already satisfies the corrected
 * rule, so it is kept; a row this command has already cleared no longer matches
 * the query at all.
 */
#[Description('Re-judge unanswered-question flags written before the classifier fix')]
class ReclassifyUnansweredCommand extends Command
{
    protected $signature = 'reclassify:unanswered
        {--dry-run : Report what would change without writing anything}
        {--before= : Only rows created before this date/time (default: all flagged rows)}
        {--chunk=500 : Rows to load per batch}';

    public function handle(NonAnswerDetector $nonAnswers): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $before = $this->option('before') ? Carbon::parse($this->option('before')) : null;

        $query = Message::query()
            ->where('role', 'assistant')
            ->where('meta->unanswered', true)
            ->when($before, fn ($q) => $q->where('created_at', '<', $before))
            ->orderBy('id');

        $total = (clone $query)->count();

        $this->line('');
        $this->line($dryRun
            ? '<comment>DRY RUN</comment> — nothing will be written.'
            : '<info>APPLYING</info> — matching rows will be updated in place.');
        $this->line("Flagged assistant messages in scope: <options=bold>{$total}</>");
        if ($before) {
            $this->line('Restricted to rows created before: '.$before->toDateTimeString());
        }
        $this->line('');

        $cleared = 0;
        $kept = 0;
        $untouched = 0;
        $examples = [];

        $query->chunkById((int) $this->option('chunk'), function ($messages) use (
            $nonAnswers, $dryRun, &$cleared, &$kept, &$untouched, &$examples
        ) {
            foreach ($messages as $message) {
                $meta = $message->meta ?? [];

                // Only the branch the fix changed. See the class docblock.
                if (($meta['unanswered_reason'] ?? null) !== 'knowledge_miss') {
                    $untouched++;

                    continue;
                }

                if ($this->stillUnanswered($nonAnswers, $message, $meta)) {
                    $kept++;

                    continue;
                }

                $cleared++;

                if (count($examples) < 10) {
                    $examples[] = [
                        $message->id,
                        Str::limit((string) ($meta['unanswered_question'] ?? '—'), 44),
                        Str::limit((string) $message->content, 54),
                    ];
                }

                if (! $dryRun) {
                    $this->clear($message, $meta);
                }
            }
        });

        $this->renderSummary($cleared, $kept, $untouched, $examples, $dryRun);

        return self::SUCCESS;
    }

    /**
     * Today's rule, applied to what was stored at the time.
     *
     * Mirrors the order of the corrected `classifyUnanswered()`: the turn is
     * answered if it closed something for the customer, if it was handed to a
     * person, or — the check that matters here and the one the old rule never
     * reached for these rows — if the reply does not actually read as "I don't
     * know".
     *
     * @param  array<string, mixed>  $meta
     */
    protected function stillUnanswered(NonAnswerDetector $nonAnswers, Message $message, array $meta): bool
    {
        if ($meta['lead_captured'] ?? false) {
            return false;
        }

        if ($meta['price_approval_requested'] ?? false) {
            return false;
        }

        // A handover is already its own category in the owner's inbox, and its
        // acknowledgement reads like an apology to any text matcher.
        if ($meta['escalated'] ?? false) {
            return false;
        }

        return $nonAnswers->matches((string) $message->content);
    }

    /**
     * Write exactly what the pipeline writes for a turn it considers answered,
     * so a backfilled row is indistinguishable from a freshly classified one —
     * plus a stamp, because a silent mass edit of historical data is not
     * something anyone should have to take on trust.
     *
     * @param  array<string, mixed>  $meta
     */
    protected function clear(Message $message, array $meta): void
    {
        $message->forceFill(['meta' => [
            ...$meta,
            'unanswered' => false,
            'unanswered_reason' => null,
            'unanswered_question' => null,
            // What the row used to say, and when it was revised. Keeps the
            // correction auditable and makes a second run's no-op visible.
            'unanswered_reclassified_at' => now()->toIso8601String(),
            'unanswered_was' => $meta['unanswered_reason'] ?? null,
        ]])->save();
    }

    /**
     * @param  array<int, array<int, string|int>>  $examples
     */
    protected function renderSummary(int $cleared, int $kept, int $untouched, array $examples, bool $dryRun): void
    {
        if ($examples !== []) {
            $this->line($dryRun ? 'Would be reclassified as answered:' : 'Reclassified as answered:');
            $this->table(['Message', 'Question asked', 'What the agent actually replied'], $examples);
        }

        $this->line('');
        $this->line(sprintf(
            '%s as answered: <options=bold>%d</>',
            $dryRun ? 'Would be reclassified' : 'Reclassified',
            $cleared,
        ));
        $this->line("Still flagged (a real gap):    <options=bold>{$kept}</>");

        if ($untouched > 0) {
            $this->line("Left alone (catalog/provider): <options=bold>{$untouched}</>");
        }

        $this->line('');

        if ($dryRun && $cleared > 0) {
            $this->line('Re-run without <options=bold>--dry-run</> to apply.');
            $this->line('');
        }
    }
}
