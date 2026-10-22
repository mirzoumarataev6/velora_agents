<?php

namespace App\Services\Quality;

use App\Models\Conversation;
use App\Models\Message;

class FeedbackCadence
{
    public function __construct(
        protected int $floor,
        protected ClosingMessageDetector $closings = new ClosingMessageDetector,
    ) {}

    public static function fromConfig(): self
    {
        return new self(floor: max(1, (int) config('agent.feedback.min_turns_between', 3)));
    }

    public function shouldAsk(Conversation $conversation, Message $reply, ?Message $customerMessage = null): bool
    {
        if (! $this->isWorthRating($reply)) {
            return false;
        }

        if ($this->turnsSinceLastPromptOrRating($conversation, $reply) < $this->floor) {
            return false;
        }

        return $this->closedSomething($reply)
            || $this->customerIsWrappingUp($conversation, $reply, $customerMessage);
    }

    public function markAsked(Message $reply): void
    {
        $reply->forceFill(['meta' => [...$reply->meta ?? [], 'feedback_requested' => true]])->save();
    }

    protected function isWorthRating(Message $reply): bool
    {
        $meta = $reply->meta ?? [];

        return ! ($meta['failed'] ?? false)
            && ! ($meta['escalated'] ?? false)
            && ! ($meta['greeting'] ?? false);
    }

    protected function closedSomething(Message $reply): bool
    {
        $meta = $reply->meta ?? [];

        return ($meta['lead_captured'] ?? false) || ($meta['price_approval_requested'] ?? false);
    }

    protected function customerIsWrappingUp(Conversation $conversation, Message $reply, ?Message $customerMessage): bool
    {
        $text = $customerMessage?->content ?? $conversation->messages()
            ->where('role', 'user')
            ->where('id', '<', $reply->id)
            ->orderByDesc('id')
            ->value('content');

        return $this->closings->matches((string) $text);
    }

    protected function turnsSinceLastPromptOrRating(Conversation $conversation, Message $reply): int
    {
        $marker = $conversation->messages()
            ->where('role', 'assistant')
            ->where('id', '<', $reply->id)
            ->where(fn ($query) => $query
                ->whereNotNull('rating')
                ->orWhere('meta->feedback_requested', true)
                ->orWhere('meta->session_start', true))
            ->orderByDesc('id')
            ->value('id');

        return $conversation->messages()
            ->where('role', 'assistant')
            ->where('id', '<=', $reply->id)
            ->when($marker, fn ($query) => $query->where('id', '>', $marker))
            ->count();
    }
}
