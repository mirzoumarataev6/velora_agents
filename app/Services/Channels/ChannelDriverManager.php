<?php

namespace App\Services\Channels;

use App\Models\Channel;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

class ChannelDriverManager
{
    protected array $drivers = [
        'telegram' => TelegramDriver::class,
        'whatsapp' => WhatsAppDriver::class,
        'instagram' => InstagramDriver::class,
        'greenapi' => GreenApiDriver::class,
        'instagrapi' => InstagrapiDriver::class,
    ];

    // Создаёт менеджер драйверов каналов с контейнером для их разрешения
    public function __construct(protected Container $container) {}

    // Возвращает драйвер, соответствующий типу указанного канала
    public function for(Channel $channel): ChannelDriverInterface
    {
        return $this->driver($channel->type);
    }

    // Возвращает драйвер канала по его типу
    public function driver(string $type): ChannelDriverInterface
    {
        if (! isset($this->drivers[$type])) {
            throw new InvalidArgumentException("No channel driver registered for type [{$type}].");
        }

        return $this->container->make($this->drivers[$type]);
    }
}
