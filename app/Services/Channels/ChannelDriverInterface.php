<?php

namespace App\Services\Channels;

use App\Models\Channel;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

interface ChannelDriverInterface
{
    // Подтверждает вебхук перед каналом при его первичной настройке
    public function verifyWebhook(Request $request, Channel $channel): ?Response;

    // Проверяет подлинность входящего запроса от канала
    public function authenticateIncomingRequest(Request $request, Channel $channel): bool;

    // Разбивает входящий пейлоад на отдельные доставки сообщений
    public function splitDeliveries(array $payload): array;

    // Преобразует пейлоад канала во внутреннее представление входящего сообщения
    public function parseIncomingMessage(array $payload, Channel $channel): ?IncomingMessage;

    // Отправляет текстовое сообщение пользователю канала
    public function sendMessage(Channel $channel, string $externalUserId, string $text): void;

    // Сообщает, поддерживает ли канал обратную связь по сообщениям (реакции)
    public function supportsMessageFeedback(): bool;

    // Отправляет сообщение с возможностью последующей обратной связи от пользователя
    public function sendMessageWithFeedback(Channel $channel, string $externalUserId, string $text, int $messageId): void;

    // Преобразует пейлоад канала в объект обратной связи по сообщению
    public function parseFeedback(array $payload, Channel $channel): ?MessageFeedback;

    // Подтверждает получение обратной связи ответом пользователю
    public function confirmFeedback(Channel $channel, MessageFeedback $feedback, string $confirmation): void;

    // Отправляет индикатор набора текста пользователю канала
    public function sendTypingIndicator(Channel $channel, string $externalUserId): void;

    // Подключает канал и возвращает данные о результате подключения
    public function connect(Channel $channel): array;

    // Отключает канал
    public function disconnect(Channel $channel): void;
}
