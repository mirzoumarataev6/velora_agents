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
use App\Services\Channels\PhotoReplyService;
use App\Services\Channels\PhotoRequestContext;
use App\Services\Quality\FeedbackCadence;
use App\Services\Quality\MessageFeedbackRecorder;
use App\Services\Reply\CustomerLocale;
use App\Services\TelegramNotifyLinker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class HandleIncomingMessageJob implements ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public int $tries = 3;

    public int $timeout = 120;

    public array $backoff = [10, 45];

    protected const UPDATE_DEDUP_TTL = 900;

    // Создаёт задачу обработки входящего вебхука канала
    public function __construct(public Channel $channel, public array $payload) {}

    // Обрабатывает входящее сообщение или обратную связь и отправляет ответ клиенту
    public function handle(
        ChannelDriverManager $drivers,
        AgentReplyService $replyService,
        TelegramNotifyLinker $linker,
        HumanizedReplySender $sender,
        MessageFeedbackRecorder $ratings,
        FeedbackCadence $cadence,
        CustomerLocale $locales,
        PhotoRequestContext $photoContext,
        PhotoReplyService $photoReplies,
    ): void {
        if (! $this->claimUpdate()) {
            return;
        }

        $driver = $drivers->for($this->channel);

        $feedback = $driver->parseFeedback($this->payload, $this->channel);

        if ($feedback !== null) {
            $ratings->record($driver, $this->channel, $feedback);

            return;
        }

        $incoming = $driver->parseIncomingMessage($this->payload, $this->channel);

        if ($incoming === null) {
            return;
        }

        if ($this->channel->type === 'telegram' && $this->handleNotifyLink($incoming, $driver, $linker)) {
            return;
        }

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
            return;
        }

        if ($userMessage->wasRecentlyCreated) {
            MessageReceived::dispatch($userMessage);
        }

        if ($conversation->status === 'human_takeover' || $agent->status === 'paused') {
            $conversation->update(['last_message_at' => now()]);

            return;
        }

        $photo = in_array($this->channel->type, ['telegram', 'whatsapp', 'greenapi'], true)
            ? $photoContext->resolve($conversation, $userMessage) : ['requested' => false];

        if (! $photo['requested'] && $replyService->shouldSuppressReply($conversation)) {
            Log::info('Suppressing reply: previous fallback still within suppression window', [
                'channel_id' => $this->channel->id,
                'conversation_id' => $conversation->id,
            ]);
            $conversation->update(['last_message_at' => now()]);

            return;
        }

        $driver->sendTypingIndicator($this->channel, $incoming->externalUserId);

        if ($photo['requested']) {
            $assistantMessage = $photoReplies->reply($driver, $this->channel, $conversation, $userMessage, $photo);
        } else {
            $assistantMessage = $incoming->type === 'text' && AgentReplyService::isStartCommand($incoming->text)
            ? $replyService->greetOnStart($conversation)
            : match ($incoming->type) {
                'photo_unclear' => $replyService->askForProductName($conversation, $userMessage),
                'voice_failed', 'voice_too_long' => $replyService->explainVoiceProblem(
                    $conversation,
                    $userMessage,
                    $incoming->type,
                ),
                'unsupported' => $replyService->explainVoiceProblem(
                    $conversation,
                    $userMessage,
                    'unsupported_attachment',
                ),
                default => $replyService->generateReplyFor($conversation, $userMessage),
            };
        }

        $askForRating = $driver->supportsMessageFeedback()
            && $cadence->shouldAsk($conversation, $assistantMessage, $userMessage);

        try {
            $sender->send(
                $driver,
                $this->channel,
                $incoming->externalUserId,
                $assistantMessage->content,
                $askForRating ? $assistantMessage : null,
                $askForRating ? __('feedback.prompt', [], $locales->forCustomer($conversation)) : null,
            );

            if ($askForRating) {
                $cadence->markAsked($assistantMessage);
            }
        } catch (Throwable $e) {
            Log::error('Failed to deliver channel reply', [
                'channel_id' => $this->channel->id,
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // Уведомляет клиента и владельца, если задача окончательно провалилась
    public function failed(?Throwable $e): void
    {
        Log::error('Incoming message job failed permanently — no reply was sent', [
            'channel_id' => $this->channel->id,
            'channel_type' => $this->channel->type,
            'error' => $e?->getMessage(),
        ]);

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

    // Отправляет клиенту сообщение об извинении после окончательного сбоя
    protected function apologiseToCustomer(): void
    {
        $driver = app(ChannelDriverManager::class)->for($this->channel);
        $incoming = $driver->parseIncomingMessage($this->payload, $this->channel);

        if ($incoming === null) {
            return;
        }

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

    // Уведомляет владельца агента в Telegram о том, что сообщение осталось без ответа
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

    // Атомарно помечает вебхук как обработанный, отсеивая повторные доставки
    protected function claimUpdate(): bool
    {
        $updateId = $this->payload['update_id']
            ?? 'sha:'.hash('sha256', json_encode($this->payload));

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

    // Проверяет, что на это сообщение уже был отправлен ответ
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

        return Message::where('conversation_id', $message->conversation_id)
            ->whereIn('role', ['assistant', 'operator'])
            ->where('id', '>', $message->id)
            ->exists();
    }

    // Формирует ключ кэша для дедупликации извинения по конкретному обновлению
    protected function apologyKey(string $externalUserId): string
    {
        $updateId = $this->payload['update_id'] ?? md5(json_encode($this->payload));

        return "webhook-apology:{$this->channel->id}:{$externalUserId}:{$updateId}";
    }

    // Обрабатывает команду привязки Telegram владельца для получения уведомлений о заявках
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

    // Находит существующий диалог с клиентом или создаёт новый
    protected function findOrCreateConversation(Agent $agent, IncomingMessage $incoming): Conversation
    {
        try {
            return $agent->conversations()->firstOrCreate(
                ['channel_id' => $this->channel->id, 'external_user_id' => $incoming->externalUserId],
                ['external_user_name' => $incoming->externalUserName, 'status' => 'ai']
            );
        } catch (UniqueConstraintViolationException) {
            return $agent->conversations()
                ->where('channel_id', $this->channel->id)
                ->where('external_user_id', $incoming->externalUserId)
                ->firstOrFail();
        }
    }

    // Сохраняет сообщение клиента, атомарно защищаясь от повторной записи
    protected function claimUserMessage(Conversation $conversation, IncomingMessage $incoming): ?Message
    {
        try {
            return $conversation->messages()->create([
                'role' => 'user',
                'content' => $incoming->text,
                'external_message_id' => $incoming->externalMessageId,
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
