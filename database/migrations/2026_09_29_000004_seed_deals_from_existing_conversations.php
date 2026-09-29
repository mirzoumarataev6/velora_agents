<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Gives the board the business that already happened.
 *
 * Without this the kanban opens empty on an account with months of history,
 * which reads as broken rather than as new. Every card is placed by the SAME
 * rules DealStageAutomation applies live — an order lead means Оплата, a haggle
 * lead means Торг, a real exchange means Переписка — so nothing here invents a
 * stage the live system would not have produced. Доставка, Завершено and
 * Отменено are left alone, because there is no signal for them and guessing
 * would hand the owner a board full of confident fiction.
 *
 * Written against the query builder, like the customer backfill: a historical
 * import must keep producing the same result after the live rules evolve.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('conversations')
            ->join('agents', 'agents.id', '=', 'conversations.agent_id')
            ->whereNotNull('conversations.customer_id')
            // A thread nobody ever wrote in is not a piece of business.
            ->whereExists(fn ($q) => $q->selectRaw(1)->from('messages')
                ->whereColumn('messages.conversation_id', 'conversations.id'))
            ->select([
                'conversations.id',
                'conversations.customer_id',
                'conversations.agent_id',
                'conversations.created_at',
                'conversations.last_message_at',
                'agents.user_id',
            ])
            ->chunkById(300, function ($conversations) {
                foreach ($conversations as $conversation) {
                    $this->seed($conversation);
                }
            }, 'conversations.id', 'id');
    }

    public function down(): void
    {
        DB::table('deals')->delete();
    }

    private function seed(object $conversation): void
    {
        $order = DB::table('leads')
            ->where('conversation_id', $conversation->id)
            ->where('type', '!=', 'haggle')
            ->orderBy('id')
            ->first(['id', 'items']);

        $haggle = DB::table('leads')
            ->where('conversation_id', $conversation->id)
            ->where('type', 'haggle')
            ->orderByDesc('id')
            ->first(['id', 'approved_price']);

        $stage = match (true) {
            $order !== null => 'payment',
            $haggle !== null && $haggle->approved_price !== null => 'payment',
            $haggle !== null => 'haggling',
            $this->hasRealExchange($conversation->id) => 'messaging',
            default => 'new_lead',
        };

        DB::table('deals')->insert([
            'user_id' => $conversation->user_id,
            'customer_id' => $conversation->customer_id,
            'conversation_id' => $conversation->id,
            'agent_id' => $conversation->agent_id,
            'lead_id' => $order->id ?? $haggle->id ?? null,
            'title' => $order->items ?? null,
            'amount' => $haggle->approved_price ?? null,
            'payment_status' => 'unpaid',
            'stage' => $stage,
            'position' => 0,
            // The last thing that happened is the best available estimate of
            // when this deal reached where it is; "time in stage" would
            // otherwise claim every historical deal landed today.
            'stage_changed_at' => $conversation->last_message_at ?? $conversation->created_at,
            'stage_set_by_owner_at' => null,
            'created_at' => $conversation->created_at,
            'updated_at' => now(),
        ]);
    }

    /** Mirrors DealStageAutomation::hasRealExchange. */
    private function hasRealExchange(int $conversationId): bool
    {
        $counts = DB::table('messages')
            ->where('conversation_id', $conversationId)
            ->selectRaw('role, count(*) as aggregate')
            ->groupBy('role')
            ->pluck('aggregate', 'role');

        $answered = ($counts['assistant'] ?? 0) + ($counts['operator'] ?? 0);

        return $answered >= 1 && ($counts['user'] ?? 0) >= 2;
    }
};
