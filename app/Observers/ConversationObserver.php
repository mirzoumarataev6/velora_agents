<?php

namespace App\Observers;

use App\Models\Conversation;
use App\Services\Crm\CustomerResolver;
use App\Services\Crm\DealStageAutomation;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Keeps the CRM's view of a person in step with their threads.
 *
 * On the model rather than in HandleIncomingMessageJob because a conversation
 * is created in more than one place, and a customer that exists only for
 * threads that arrived through the webhook would be a book with holes in it.
 *
 * Every hook is wrapped: failing to file a customer must never cost the
 * business the message that created them.
 */
class ConversationObserver
{
    public function __construct(
        protected CustomerResolver $customers,
        protected DealStageAutomation $deals,
    ) {}

    public function created(Conversation $conversation): void
    {
        $this->resolve($conversation);
    }

    /**
     * A thread that has just moved is a customer who has just been in touch —
     * that, and nothing else about a conversation, changes the profile.
     */
    public function updated(Conversation $conversation): void
    {
        if ($conversation->wasChanged('last_message_at')) {
            $this->resolve($conversation);
        }
    }

    private function resolve(Conversation $conversation): void
    {
        try {
            $customer = $this->customers->resolveForConversation($conversation);

            // A new thread is a new card in Новый лид. Only on creation: a
            // thread that merely moved is not a second piece of business.
            if ($customer !== null && $conversation->wasRecentlyCreated) {
                $this->deals->conversationStarted($conversation, $customer);
            }
        } catch (Throwable $e) {
            Log::warning('Could not file a conversation against a CRM customer', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
