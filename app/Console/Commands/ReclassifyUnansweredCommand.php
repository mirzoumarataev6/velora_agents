<?php

namespace App\Console\Commands;

use App\Models\Message;
use App\Services\Quality\NonAnswerDetector;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

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

    protected function stillUnanswered(NonAnswerDetector $nonAnswers, Message $message, array $meta): bool
    {
        if ($meta['lead_captured'] ?? false) {
            return false;
        }

        if ($meta['price_approval_requested'] ?? false) {
            return false;
        }

        if ($meta['escalated'] ?? false) {
            return false;
        }

        return $nonAnswers->matches((string) $message->content);
    }

    protected function clear(Message $message, array $meta): void
    {
        $message->forceFill(['meta' => [
            ...$meta,
            'unanswered' => false,
            'unanswered_reason' => null,
            'unanswered_question' => null,

            'unanswered_reclassified_at' => now()->toIso8601String(),
            'unanswered_was' => $meta['unanswered_reason'] ?? null,
        ]])->save();
    }

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
