<?php

namespace App\Jobs;

use App\Services\AgentReplyService;
use App\Services\Channels\HumanizedReplySender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use ReflectionMethod;

class VerifyWorkerCodeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(AgentReplyService $replyService): void
    {
        $checks = [];

        try {
            $method = new ReflectionMethod($replyService, 'isSimpleGreeting');
            $method->setAccessible(true);
            $checks['greeting_detection'] = $method->invoke($replyService, 'привет') === true
                ? 'ok'
                : 'present but returned false for "привет" — pattern regressed';
        } catch (\ReflectionException) {
            $checks['greeting_detection'] = 'MISSING — this worker is running code from before the greeting fix';
        }

        $checks['fallback_suppression'] = method_exists($replyService, 'shouldSuppressReply')
            ? 'ok'
            : 'MISSING — this worker is running code from before the suppression fix';

        $checks['duplicate_reply_guard'] = method_exists(HandleIncomingMessageJob::class, 'createUserMessageOrNull')
            ? 'ok'
            : 'MISSING — this worker is running code from before the duplicate-reply fix';

        try {
            $sender = app(HumanizedReplySender::class);
            $long = str_repeat("Довольно длинный абзац с полезной информацией о товаре.\n\n", 5);
            $count = count($sender->messagesFor(trim($long)));

            $checks['humanized_replies'] = $count > 1
                ? 'ok'
                : "present but a long reply produced {$count} message(s) — splitting regressed";
        } catch (\Throwable $e) {
            $checks['humanized_replies'] = 'MISSING — this worker is running code from before human-like replies: '.$e->getMessage();
        }

        $allOk = ! in_array(false, array_map(fn ($v) => $v === 'ok', $checks), true);

        Log::channel('stack')->info('worker.selfcheck', [
            'result' => $allOk ? 'PASS — this worker is running current code' : 'FAIL — this worker is STALE, restart it',
            'pid' => getmypid(),
            'checked_at' => now()->toDateTimeString(),
            'checks' => $checks,
        ]);
    }
}
