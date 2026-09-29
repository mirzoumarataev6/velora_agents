<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hangs the existing history off the new customer profiles.
 *
 * The backfill is written against the query builder rather than against
 * CustomerResolver on purpose. A migration has to keep producing the same
 * result years from now; the resolver is live code that will grow new rules,
 * and a historical import that quietly changes shape when it does is a bug
 * nobody would think to look for.
 *
 * Playground threads are skipped throughout: that is the owner testing their
 * own agent, and turning them into customers would put the owner in their own
 * CRM on day one.
 */
return new class extends Migration
{
    /** Channel types whose external_user_id IS the customer's phone number. */
    private const PHONE_CHANNELS = ['whatsapp', 'greenapi'];

    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            // nullOnDelete, not cascade: deleting a customer profile must not
            // take the transcripts with it.
            $table->foreignId('customer_id')->nullable()->after('agent_id')
                ->constrained()->nullOnDelete();
            $table->index(['customer_id', 'last_message_at']);
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('conversation_id')
                ->constrained()->nullOnDelete();
            $table->index('customer_id');
        });

        $this->backfillFromConversations();
        $this->backfillOrphanLeads();
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->dropIndex(['customer_id']);
            $table->dropColumn('customer_id');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->dropIndex(['customer_id', 'last_message_at']);
            $table->dropColumn('customer_id');
        });
    }

    /** Every real (non-playground) thread becomes, or joins, a customer. */
    private function backfillFromConversations(): void
    {
        DB::table('conversations')
            ->join('agents', 'agents.id', '=', 'conversations.agent_id')
            ->leftJoin('channels', 'channels.id', '=', 'conversations.channel_id')
            ->whereNotNull('conversations.channel_id')
            ->select([
                'conversations.id',
                'conversations.external_user_id',
                'conversations.external_user_name',
                'conversations.created_at',
                'conversations.last_message_at',
                'agents.user_id',
                'channels.type as channel_type',
            ])
            ->chunkById(300, function ($conversations) {
                foreach ($conversations as $conversation) {
                    $lead = DB::table('leads')
                        ->where('conversation_id', $conversation->id)
                        ->orderBy('id')
                        ->first(['customer_name', 'phone']);

                    $phone = in_array($conversation->channel_type, self::PHONE_CHANNELS, true)
                        ? $conversation->external_user_id
                        : null;

                    $phone ??= $lead->phone ?? null;

                    $customerId = $this->customerFor(
                        userId: (int) $conversation->user_id,
                        phone: $phone,
                        name: $conversation->external_user_name ?: ($lead->customer_name ?? null),
                        source: $conversation->channel_type,
                        firstContactAt: $conversation->created_at,
                        lastContactAt: $conversation->last_message_at ?: $conversation->created_at,
                    );

                    DB::table('conversations')->where('id', $conversation->id)
                        ->update(['customer_id' => $customerId]);

                    DB::table('leads')->where('conversation_id', $conversation->id)
                        ->update(['customer_id' => $customerId]);
                }
            }, 'conversations.id', 'id');
    }

    /**
     * Leads whose conversation was pruned. They kept the contact details on
     * purpose (see the leads migration), so a number here is still a customer.
     */
    private function backfillOrphanLeads(): void
    {
        DB::table('leads')
            ->join('agents', 'agents.id', '=', 'leads.agent_id')
            ->whereNull('leads.customer_id')
            ->whereNotNull('leads.phone')
            ->select(['leads.id', 'leads.customer_name', 'leads.phone', 'leads.source', 'leads.created_at', 'agents.user_id'])
            // chunkById, not chunk: the loop writes customer_id, which is the
            // very column the WHERE filters on, so offset paging would step
            // over a page of leads for every page it processed.
            ->chunkById(300, function ($leads) {
                foreach ($leads as $lead) {
                    $customerId = $this->customerFor(
                        userId: (int) $lead->user_id,
                        phone: $lead->phone,
                        name: $lead->customer_name,
                        source: $lead->source,
                        firstContactAt: $lead->created_at,
                        lastContactAt: $lead->created_at,
                    );

                    DB::table('leads')->where('id', $lead->id)->update(['customer_id' => $customerId]);
                }
            }, 'leads.id', 'id');
    }

    /** Matches on the phone key when there is one, otherwise opens a new profile. */
    private function customerFor(
        int $userId,
        ?string $phone,
        ?string $name,
        ?string $source,
        ?string $firstContactAt,
        ?string $lastContactAt,
    ): int {
        $key = $this->phoneKey($phone);

        if ($key !== null) {
            $existing = DB::table('customers')
                ->where('user_id', $userId)->where('phone_key', $key)
                ->first(['id', 'name', 'first_contact_at', 'last_contact_at']);

            if ($existing !== null) {
                DB::table('customers')->where('id', $existing->id)->update([
                    'name' => $existing->name ?: $name,
                    'first_contact_at' => min(array_filter([$existing->first_contact_at, $firstContactAt])) ?: null,
                    'last_contact_at' => max(array_filter([$existing->last_contact_at, $lastContactAt])) ?: null,
                    'updated_at' => now(),
                ]);

                return (int) $existing->id;
            }
        }

        return (int) DB::table('customers')->insertGetId([
            'user_id' => $userId,
            'name' => $name,
            'phone' => $key !== null ? $phone : null,
            'phone_key' => $key,
            'source' => $source,
            'status' => 'lead',
            'first_contact_at' => $firstContactAt,
            'last_contact_at' => $lastContactAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Mirrors CustomerResolver::phoneKey — see it for the nine-digit reasoning. */
    private function phoneKey(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone) ?? '';

        return strlen($digits) >= 9 && strlen($digits) <= 15 ? substr($digits, -9) : null;
    }
};
