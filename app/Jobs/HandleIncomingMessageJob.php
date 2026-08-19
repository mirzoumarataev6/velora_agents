<?php

namespace App\Jobs;

use App\Events\MessageReceived;
use App\Models\Agent;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\AgentReplyService;
use App\Services\Channels\ChannelDriverInterface;
use App\Services\Channels\ChannelDriverManager;
use App\Services\Channels\HumanizedReplySender;
use App\Services\Channels\IncomingMessage;
use App\Services\TelegramNotifyLinker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Full incoming-webhook-to-outgoing-reply flow for every channel:
 * resolve channel -> find/create conversation -> save inbound message ->
 * skip auto-reply if human_takeover or the agent is paused -> otherwise run
 * RAG and send the reply back through the same driver.
 *
 * Idempotency is enforced at the database level (unique constraints on
 * conversations(channel_id, external_user_id) and
 * messages(conversation_id, external_message_id)), not just the fast-path
 * SELECT below — a job can legitimately be dispatched more than once for the
 * same update (a redelivered webhook, or a retry after the queue worker's
 * timeout killed a slow-but-still-running attempt), and only one of those
 * executions may end up sending a reply.
 */
class HandleIncomingMessageJob implements ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public int $tries = 2;

    public int $timeout = 120;

    /** Seconds before the single retry — long enough to outlast a network blip. */
    public array $backoff = [15];

    /**
     * @param  array<string, mixed>  $payload  Decoded webhook JSON body.
     */
    public function __construct(public Channel $channel, public array $payload) {}

    public function handle(
        ChannelDriverManager $drivers,
        AgentReplyService $replyService,
        TelegramNotifyLinker $linker,
        HumanizedReplySender $sender,
    ): void {
        $driver = $drivers->for($this->channel);

        $incoming = $driver->parseIncomingMessage($this->payload, $this->channel);

        if ($incoming === null) {
            return; // Not a message type we handle (e.g. non-message update).
        }

        // Owner linking their personal Telegram for lead alerts: "/start notify_<code>".
        // Handle it here and stop — it must never become a customer message or
        // trigger an AI reply.
        if ($this->channel->type === 'telegram' && $this->handleNotifyLink($incoming, $driver, $linker)) {
            return;
        }

        // Fast-path dedup: cheap optimization that avoids most duplicate work
        // (Vision calls, embeddings, generation). Not the source of truth —
        // the unique constraints below are what actually prevent a duplicate
        // reply if two executions race past this check.
        $alreadyProcessed = Message::where('external_message_id', $incoming->externalMessageId)
            ->whereHas('conversation', fn ($q) => $q->where('channel_id', $this->channel->id))
            ->exists();

        if ($alreadyProcessed) {
            return;
        }

        $agent = $this->channel->agent;
        $conversation = $this->findOrCreateConversation($agent, $incoming);

        if ($incoming->externalUserName && $conversation->external_user_name !== $incoming->externalUserName) {
            $conversation->update(['external_user_name' => $incoming->externalUserName]);
        }

        $userMessage = $this->createUserMessageOrNull($conversation, $incoming);

        if ($userMessage === null) {
            return; // A concurrent/duplicate execution already claimed this update — nothing more to do.
        }

        MessageReceived::dispatch($userMessage);

        // Human takeover or a paused agent: record the message but don't auto-reply.
        if ($conversation->status === 'human_takeover' || $agent->status === 'paused') {
            $conversation->update(['last_message_at' => now()]);

            return;
        }

        // Gemini already failed recently in this conversation (e.g. a daily quota
        // exhaustion that won't clear for hours): don't spam the customer with
        // another identical canned reply, and don't waste a doomed API call.
        // The message above is still saved — the owner can follow up from the dashboard.
        if ($replyService->shouldSuppressReply($conversation)) {
            Log::info('Suppressing reply: previous fallback still within suppression window', [
                'channel_id' => $this->channel->id,
                'conversation_id' => $conversation->id,
            ]);
            $conversation->update(['last_message_at' => now()]);

            return;
        }

        $driver->sendTypingIndicator($this->channel, $incoming->externalUserId);

        // A successfully transcribed voice note is deliberately absent from this
        // match: it goes down the ordinary path, because by then it IS ordinary
        // text and must get the full pipeline — RAG, rules, tools and all.
        $assistantMessage = match ($incoming->type) {
            'photo_unclear' => $replyService->askForProductName($conversation, $userMessage),
            'voice_disabled', 'voice_failed', 'voice_too_long' => $replyService->explainVoiceProblem(
                $conversation,
                $userMessage,
                $incoming->type,
            ),
            default => $replyService->generateReplyFor($conversation, $userMessage),
        };

        // One assistant turn, possibly delivered as 2-3 human-paced messages —
        // the Message row above is still the single record of this reply.
        try {
            $sender->send(
                $driver,
                $this->channel,
                $incoming->externalUserId,
                $assistantMessage->content,
                $agent,
            );
        } catch (Throwable $e) {
            Log::error('Failed to deliver channel reply', [
                'channel_id' => $this->channel->id,
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Every attempt is spent (or the worker died holding this job). The
     * customer's message is already stored, so there is nothing to un-stick —
     * but this must be loud, because from the outside it looks like the bot
     * simply ignored someone.
     */
    public function failed(?Throwable $e): void
    {
        Log::error('Incoming message job failed permanently — no reply was sent', [
            'channel_id' => $this->channel->id,
            'channel_type' => $this->channel->type,
            'error' => $e?->getMessage(),
        ]);
    }

    /**
     * If the incoming message is the owner's "/start notify_<code>" linking
     * command, consume it (store their chat id, reply with a confirmation) and
     * return true so the caller stops before any customer-message processing.
     */
    protected function handleNotifyLink(IncomingMessage $incoming, ChannelDriverInterface $driver, TelegramNotifyLinker $linker): bool
    {
        if ($incoming->type !== 'text') {
            return false;
        }

        $code = $linker->codeFromCommand($incoming->text);

        if ($code === null) {
            return false;
        }

        $linked = $linker->complete($this->channel->agent, $code, $incoming->externalUserId, $incoming->externalUserName);

        try {
            $driver->sendMessage(
                $this->channel,
                $incoming->externalUserId,
                $linked
                    ? '✅ Готово! Вы будете получать уведомления о новых заявках в этом чате.'
                    : '⚠️ Ссылка для привязки недействительна или устарела. Сгенерируйте новую в панели управления.'
            );
        } catch (Throwable $e) {
            Log::warning('Failed to send Telegram notify-link confirmation', [
                'channel_id' => $this->channel->id,
                'error' => $e->getMessage(),
            ]);
        }

        return true;
    }

    protected function findOrCreateConversation(Agent $agent, IncomingMessage $incoming): Conversation
    {
        try {
            return $agent->conversations()->firstOrCreate(
                ['channel_id' => $this->channel->id, 'external_user_id' => $incoming->externalUserId],
                ['external_user_name' => $incoming->externalUserName, 'status' => 'ai']
            );
        } catch (UniqueConstraintViolationException) {
            // Lost the race to a concurrent execution — fetch the row it just created.
            return $agent->conversations()
                ->where('channel_id', $this->channel->id)
                ->where('external_user_id', $incoming->externalUserId)
                ->firstOrFail();
        }
    }

    protected function createUserMessageOrNull(Conversation $conversation, IncomingMessage $incoming): ?Message
    {
        try {
            return $conversation->messages()->create([
                'role' => 'user',
                'content' => $incoming->text,
                'external_message_id' => $incoming->externalMessageId,
                // The transcript is stored as ordinary text; this is what lets
                // the inbox show that the customer spoke rather than typed.
                'source_type' => $incoming->isVoice() ? 'voice' : 'text',
                'voice_duration' => $incoming->voiceDuration,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }
}
