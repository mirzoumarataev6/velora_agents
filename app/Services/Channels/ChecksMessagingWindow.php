<?php

namespace App\Services\Channels;

use App\Models\Channel;
use App\Models\Conversation;

trait ChecksMessagingWindow
{
    protected const CUSTOMER_SERVICE_WINDOW_HOURS = 24;

    protected function assertWithinMessagingWindow(Channel $channel, string $externalUserId): void
    {
        if ($this->isWithinCustomerServiceWindow($channel, $externalUserId)) {
            return;
        }

        throw new OutsideMessagingWindowException(sprintf(
            'The %d-hour messaging window for this customer has closed; %s will not deliver a free-form message until they write again.',
            self::CUSTOMER_SERVICE_WINDOW_HOURS,
            $channel->type === 'instagram' ? 'Instagram' : 'WhatsApp',
        ));
    }

    protected function isWithinCustomerServiceWindow(Channel $channel, string $externalUserId): bool
    {
        $lastInbound = Conversation::where('channel_id', $channel->id)
            ->where('external_user_id', $externalUserId)
            ->first()
            ?->messages()
            ->where('role', 'user')
            ->latest('id')
            ->first();

        return $lastInbound !== null
            && $lastInbound->created_at->gt(now()->subHours(self::CUSTOMER_SERVICE_WINDOW_HOURS));
    }
}
