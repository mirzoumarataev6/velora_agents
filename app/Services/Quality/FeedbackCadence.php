<?php

namespace App\Services\Quality;

use App\Models\Conversation;
use App\Models\Message;

/**
 * How often a customer is asked to rate a reply.
 *
 * The first version asked after every single turn, which is the mistake this
 * class exists to correct. A rating control under all of them stops reading as
 * a question and starts reading as furniture: it clutters the chat, it trains
 * the customer to ignore it, and the votes that do arrive are reflexes rather
 * than verdicts. Asking rarely is what keeps the answer worth having.
 *
 * THE RULE, in one line — where `turnsSince` counts assistant turns since the
 * last turn that either carried a prompt or received a rating (or since the
 * conversation began, if neither has happened yet):
 *
 *     ask  ⟺  turnsSince >= FLOOR  AND  (this turn captured a lead
 *                                        OR turnsSince >= INTERVAL)
 *
 *  - FLOOR (3) is the minimum gap between two prompts. Because the count
 *    restarts on a RATING as well as on a prompt, answering one buys the same
 *    quiet as being asked one — a customer who has just voted is not asked
 *    again in the same exchange. It also puts the first possible prompt at
 *    turn 3: nobody can judge an assistant on its opening line.
 *
 *  - INTERVAL (5) is the routine cadence when nothing notable happens.
 *
 *  - A CAPTURED LEAD is the shortcut. It is the moment the conversation
 *    achieved what it was for, which is both the most informative point to ask
 *    at and a naturally rare one. It can bring a prompt forward, never inside
 *    the floor.
 *
 * Worked through: turns 1-4 pass in silence, 5 asks, 6-7 are inside the floor,
 * 8 asks only if an order lands, 10 asks routinely. A vote on turn 5 restarts
 * the same clock from there.
 *
 * WHAT THIS DELIBERATELY DOES NOT DO is prompt after a lull, which would be the
 * other natural "conversation is wrapping up" signal. Nothing in this pipeline
 * runs except on an inbound message, so a lull is invisible here — detecting
 * one would need a scheduled job whose only purpose is to send rating prompts
 * into quiet chats. A captured lead is the honest proxy for the same moment.
 */
class FeedbackCadence
{
    public function __construct(protected int $floor, protected int $interval) {}

    public static function fromConfig(): self
    {
        return new self(
            floor: max(1, (int) config('agent.feedback.min_turns_between', 3)),
            interval: max(1, (int) config('agent.feedback.every_n_turns', 5)),
        );
    }

    /**
     * Should this reply carry a 👍/👎 control?
     *
     * Called with the assistant Message the turn produced, before it is
     * delivered. Says nothing about whether the CHANNEL can carry one — that is
     * the driver's to answer, and the caller checks it.
     */
    public function shouldAsk(Conversation $conversation, Message $reply): bool
    {
        if (! $this->isWorthRating($reply)) {
            return false;
        }

        $turnsSince = $this->turnsSinceLastPromptOrRating($conversation, $reply);

        if ($turnsSince < $this->floor) {
            return false;
        }

        return $this->closedSomething($reply) || $turnsSince >= $this->interval;
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
     * the one being judged.
     */
    protected function isWorthRating(Message $reply): bool
    {
        $meta = $reply->meta ?? [];

        return ! ($meta['failed'] ?? false) && ! ($meta['escalated'] ?? false);
    }

    /**
     * Did this turn finish the job the customer came for?
     *
     * A captured lead, or a price sent to the owner for approval — both are
     * points where the customer has what they wanted and the exchange has a
     * natural seam in it.
     */
    protected function closedSomething(Message $reply): bool
    {
        $meta = $reply->meta ?? [];

        return ($meta['lead_captured'] ?? false) || ($meta['price_approval_requested'] ?? false);
    }

    /**
     * Assistant turns since the last one that was prompted or rated, counting
     * this one.
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
                ->orWhere('meta->feedback_requested', true))
            ->orderByDesc('id')
            ->value('id');

        return $conversation->messages()
            ->where('role', 'assistant')
            ->where('id', '<=', $reply->id)
            ->when($marker, fn ($query) => $query->where('id', '>', $marker))
            ->count();
    }
}
