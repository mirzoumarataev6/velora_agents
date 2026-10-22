<?php

namespace App\Observers;

use App\Models\Conversation;
use App\Services\Crm\CustomerResolver;
use App\Services\Crm\DealStageAutomation;
use Illuminate\Support\Facades\Log;
use Throwable;

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
