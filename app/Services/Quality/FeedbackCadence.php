<?php

namespace App\Services\Quality;

use App\Models\Conversation;
use App\Models\Message;

/**
 * When a customer is asked to rate a reply.
 *
 * The first version asked after every single turn; the second every fifth
 * turn. Both asked mid-flow — in the middle of choosing a laptop, between a
 * question and its follow-up — which is the least useful moment to ask and the
 * most irritating one. This version asks only where the conversation has
 * reached a natural end.
 *
 * THE RULE, in one line — where `turnsSince` counts assistant turns since the
 * last turn that carried a prompt, received a rating, or opened a session with
 * /start (or since the conversation began, if none has happened yet):
 *
 *     ask  ⟺  turnsSince >= FLOOR  AND  (this turn closed something
 *                                        OR the customer is wrapping up)
 *
 *  - FLOOR (3) is the minimum gap between two prompts. Because the count
 *    restarts on a RATING as well as on a prompt, answering one buys the same
 *    quiet as being asked one. It also puts the first possible prompt at turn
 *    3: nobody can judge an assistant on its opening line.
 *
 *  - A /start restarts the count too. Telegram keeps one chat per customer for
 *    ever, so without this a customer returning days later could be asked on
 *    their second message, because the turns from last week still counted.
 *
 *  - CLOSED SOMETHING: a captured lead, or a price sent to the owner for
 *    approval. The moment the conversation achieved what it was for.
 *
 *  - WRAPPING UP: the customer's message is nothing but thanks or an
 *    acknowledgement ("спасибо", "Фахмо рахмат", "got it") — see
 *    ClosingMessageDetector.
 *
 * There is deliberately no routine "every N turns" fallback any more: a long
 * conversation that never reaches an end is not rated, rather than being
 * interrupted at an arbitrary point in it.
 */
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

    /**
     * Should this reply carry a 👍/👎 control?
     *
     * Called with the assistant Message the turn produced, before it is
     * delivered, and the customer message it answers (looked up if not
     * given). Says nothing about whether the CHANNEL can carry one — that is
     * the driver's to answer, and the caller checks it.
     */
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

    /**
     * Record that this reply asked, so the next turn can count from here.
     *
     * Written to the message rather than to a cache key: the cadence is then
     * reconstructible from the transcript alone, survives a cache flush, and is
     * visible to anyone debugging why a customer was or was not asked.
     */
    public function markAsked(Message $reply): void
    {
        $reply->forceFill(['meta' => [...$reply->meta ?? [], 'feedback_requested' => true]])->save();
    }

    /**
     * Turns that a rating control has no business appearing under.
     *
     * A failed turn is the fallback message, and asking someone to rate the
     * apology for an outage is asking the wrong question about the wrong thing.
     * An escalated turn is on its way to a human, so the bot's answer is not
     * the one being judged. A greeting is the owner's own words, not an answer.
     */
    protected function isWorthRating(Message $reply): bool
    {
        $meta = $reply->meta ?? [];

        return ! ($meta['failed'] ?? false)
            && ! ($meta['escalated'] ?? false)
            && ! ($meta['greeting'] ?? false);
    }

    /**
     * Did this turn finish the job the customer came for?
     */
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

    /**
     * Assistant turns since the last one that was prompted, rated or opened a
     * session, counting this one.
     *
     * Two bounded queries rather than loading the transcript: a long-running
     * conversation can hold hundreds of turns, and this runs on every reply.
     */
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
