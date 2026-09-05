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
use Illuminate\Support\Facades\Cache;
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

    /**
     * Three attempts, not two. The second exists for a network blip; the third
     * for a provider that is rate-limiting or briefly down, which the backoff
     * below is spaced to ride out.
     */
    public int $tries = 3;

    public int $timeout = 120;

    /**
     * Exponential-ish, in seconds. The last gap is deliberately long: if two
     * attempts have already failed the cause is not transient jitter, and a
     * customer waiting a minute for an answer is better than one who gets none.
     *
     * MUST stay well under the connection's retry_after (see config/queue.php),
     * or the queue hands the job to a second worker while the first still holds
     * it and the customer is answered twice.
     */
    public array $backoff = [10, 45];

    /**
     * How long a redelivered update stays recognised as one already seen.
     *
     * Telegram retries a webhook it thinks was not acknowledged, and its retry
     * window is minutes, not seconds. Comfortably longer than every attempt plus
     * its backoff, so a retry of THIS job is never mistaken for a duplicate.
     */
    protected const UPDATE_DEDUP_TTL = 900;

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
        // Telegram redelivers an update it believes was not acknowledged, and
        // it does so with the same update_id. Claimed atomically, before any
        // parsing or database work: whichever execution gets the lock owns this
        // update, the rest stop here.
        //
        // Deliberately keyed on the delivery, not the message, so a redelivered
        // NON-message update (an edit, a reaction, a callback) also costs
        // nothing. The message-level constraints further down remain the source
        // of truth; this is what stops the work from being done twice at all.
        if (! $this->claimUpdate()) {
            return;
        }

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

        // Already stored AND already answered. Both halves matter.
        //
        // This check used to be "already stored", full stop — which quietly
        // disabled every retry this job has. An attempt that stored the
        // customer's message and then failed (a missing column, a provider
        // error) left the row behind, so the retry saw it, returned
        // immediately, and reported success. The customer was never answered
        // and nothing looked wrong. Requiring an actual reply is what makes a
        // retry able to finish the work its predecessor started.
        if ($this->alreadyAnswered($incoming->externalMessageId)) {
            return;
        }

        $agent = $this->channel->agent;
        $conversation = $this->findOrCreateConversation($agent, $incoming);

        if ($incoming->externalUserName && $conversation->external_user_name !== $incoming->externalUserName) {
            $conversation->update(['external_user_name' => $incoming->externalUserName]);
        }

        $userMessage = $this->claimUserMessage($conversation, $incoming);

        if ($userMessage === null) {
            return; // A concurrent/duplicate execution already claimed this update — nothing more to do.
        }

        if ($userMessage->wasRecentlyCreated) {
            MessageReceived::dispatch($userMessage);
        }

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

        // Silence is the worst outcome available. Every attempt is spent, so
        // nothing here can produce a real answer — but a customer who is told
        // something went wrong knows to wait or write again, while one who is
        // told nothing concludes the business ignored them.
        //
        // Wrapped whole: failed() throwing would replace a logged failure with
        // an unlogged one.
        try {
            $this->apologiseToCustomer();
            $this->alertOwner($e);
        } catch (Throwable $inner) {
            Log::error('Could not deliver the technical-failure notice', [
                'channel_id' => $this->channel->id,
                'error' => $inner->getMessage(),
            ]);
        }
    }

    /**
     * The last-resort message to the customer. Plain text, sent directly rather
     * than through the humanized sender — this is not a reply to pace out, and
     * the fewer moving parts on this path the better.
     */
    protected function apologiseToCustomer(): void
    {
        $driver = app(ChannelDriverManager::class)->for($this->channel);
        $incoming = $driver->parseIncomingMessage($this->payload, $this->channel);

        if ($incoming === null) {
            return;
        }

        // Only once per update, however many attempts failed, and never if the
        // customer somehow did get answered in the meantime.
        if (! Cache::add($this->apologyKey($incoming->externalUserId), true, self::UPDATE_DEDUP_TTL)) {
            return;
        }

        if ($this->alreadyAnswered($incoming->externalMessageId)) {
            return;
        }

        $driver->sendMessage(
            $this->channel,
            $incoming->externalUserId,
            $this->channel->agent?->response_format['technical_error_message']
                ?? 'Извините, произошла техническая ошибка, мы уже разбираемся.',
        );
    }

    /**
     * Tell the owner, on the Telegram chat they already linked for lead alerts.
     *
     * A customer who was never answered is invisible in the dashboard — the
     * conversation just stops — so this is the only signal that a sale may have
     * been lost to a bug.
     */
    protected function alertOwner(?Throwable $e): void
    {
        $agent = $this->channel->agent;

        if (! $agent?->notifiesOwnerViaTelegram()) {
            return;
        }

        $telegram = $agent->channels()
            ->where('type', 'telegram')
            ->where('status', 'connected')
            ->first();

        if ($telegram === null) {
            return;
        }

        app(ChannelDriverManager::class)->for($telegram)->sendMessage(
            $telegram,
            $agent->notify_telegram_chat_id,
            "⚠️ Сообщение клиента осталось без ответа из-за технической ошибки в «{$agent->name}». "
                .'Откройте диалог и ответьте вручную.'
        );
    }

    /**
     * Claim this webhook delivery, atomically.
     *
     * Cache::add is the atomic half: it writes only if the key is absent, so of
     * several executions racing on one update exactly one is told to proceed.
     *
     * The attempts() check is what keeps this from eating its own retries — the
     * first attempt claims the update, and without it every later attempt would
     * see its own claim and stop, which is a worse bug than the one this
     * prevents.
     */
    protected function claimUpdate(): bool
    {
        $updateId = $this->payload['update_id'] ?? null;

        if ($updateId === null) {
            return true; // Not a Telegram-shaped delivery; nothing to key on.
        }

        if (Cache::add("webhook-update:{$this->channel->id}:{$updateId}", true, self::UPDATE_DEDUP_TTL)) {
            return true;
        }

        if ($this->attempts() > 1) {
            return true;
        }

        Log::info('Duplicate webhook update ignored', [
            'channel_id' => $this->channel->id,
            'update_id' => $updateId,
        ]);

        return false;
    }

    /**
     * Whether this inbound message has already been ANSWERED — not merely
     * stored. See the call site for why the distinction is the whole point.
     */
    protected function alreadyAnswered(?string $externalMessageId): bool
    {
        if ($externalMessageId === null) {
            return false;
        }

        $message = Message::where('external_message_id', $externalMessageId)
            ->where('role', 'user')
            ->whereHas('conversation', fn ($q) => $q->where('channel_id', $this->channel->id))
            ->latest('id')
            ->first();

        if ($message === null) {
            return false;
        }

        // An operator's own reply counts: a human got there first.
        return Message::where('conversation_id', $message->conversation_id)
            ->whereIn('role', ['assistant', 'operator'])
            ->where('id', '>', $message->id)
            ->exists();
    }

    protected function apologyKey(string $externalUserId): string
    {
        $updateId = $this->payload['update_id'] ?? md5(json_encode($this->payload));

        return "webhook-apology:{$this->channel->id}:{$externalUserId}:{$updateId}";
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

    /**
     * The stored inbound message for this update: created now, or the one a
     * previous attempt stored before it failed.
     *
     * Returning null on the constraint violation — the old behaviour — is what
     * made the retry a no-op: the row from the failed attempt was there, so the
     * retry declined to do anything with it. Reusing the row lets the retry pick
     * the turn up exactly where it broke. It is safe because the caller has
     * already established, one check earlier, that nothing has answered it.
     */
    protected function claimUserMessage(Conversation $conversation, IncomingMessage $incoming): ?Message
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
            return $conversation->messages()
                ->where('role', 'user')
                ->where('external_message_id', $incoming->externalMessageId)
                ->latest('id')
                ->first();
        }
    }
}
