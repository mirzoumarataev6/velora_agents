<?php

namespace App\Services\Channels;

use App\Models\Channel;
use App\Models\Conversation;

/**
 * Meta's 24-hour customer service window, shared by both Meta drivers.
 *
 * WhatsApp and Instagram enforce the same rule with different words: a business
 * may send free-form messages only within 24 hours of the customer's last
 * inbound message. Outside it, WhatsApp accepts only pre-approved template
 * messages and Instagram only messages tagged HUMAN_AGENT (a 7-day allowance
 * that has to be requested for the app). Neither is implemented yet, so the
 * honest behaviour outside the window is to refuse loudly rather than to send
 * something the platform will reject.
 *
 * Checked locally before the API call rather than left to the error response,
 * for two reasons: it saves a round trip that is certain to fail, and Meta's
 * error for it (131047 / #10 re-engagement) is not always distinguishable from
 * other permission problems.
 */
trait ChecksMessagingWindow
{
    /** Hours after the customer's last message during which free-form replies are allowed. */
    protected const CUSTOMER_SERVICE_WINDOW_HOURS = 24;

    /**
     * @throws OutsideMessagingWindowException when the window has closed.
     */
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

    /**
     * Whether the customer has messaged within the window.
     *
     * Read from our own transcript rather than from the platform: it is the
     * same fact, and we already have it.
     */
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
