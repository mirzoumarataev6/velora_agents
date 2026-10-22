<?php

namespace App\Services\Crm;

use App\Models\Conversation;
use App\Models\CrmWorkspace;
use App\Models\Customer;
use App\Models\CustomerMerge;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class CustomerResolver
{
    public const PHONE_CHANNELS = ['whatsapp', 'greenapi'];

    private const MIN_DIGITS = 9;

    private const MAX_DIGITS = 15;

    public function resolveForConversation(Conversation $conversation): ?Customer
    {
        if ($conversation->channel_id === null) {
            return null;
        }

        $userId = $conversation->agent?->user_id;

        if ($userId === null) {
            return null;
        }

        $customer = $conversation->customer_id !== null
            ? Customer::find($conversation->customer_id)
            : null;

        $phone = $this->phoneFor($conversation);

        if ($customer === null) {

            $team = $conversation->agent?->team_id;
            $customer = $this->findByPhone($team, $phone) ?? $this->open($userId, $conversation, $phone);
        } elseif ($phone !== null) {

            $customer = $this->claimPhone($customer, $phone);
        }

        if ($conversation->customer_id !== $customer->id) {

            $conversation->forceFill(['customer_id' => $customer->id])->saveQuietly();
        }

        $this->touchContact($customer, $conversation);

        return $customer;
    }

    public function resolveForLead(Lead $lead): ?Customer
    {
        $conversation = $lead->conversation;

        $customer = $conversation !== null
            ? $this->resolveForConversation($conversation)
            : null;

        $phone = $lead->phone;

        if ($customer === null) {
            $userId = $lead->agent?->user_id;

            if ($userId === null || $phone === null) {

                return null;
            }

            $customer = $this->findByPhone($lead->agent?->team_id, $phone)

                ?? Customer::forceCreate([
                    'user_id' => $userId,

                    'team_id' => $lead->agent?->team_id,

                    'crm_workspace_id' => CrmWorkspace::defaultFor(User::findOrFail($userId), $lead->agent?->team_id)->id,
                    'name' => $lead->customer_name,
                    'phone' => $phone,
                    'phone_key' => $this->phoneKey($phone),
                    'source' => $lead->source,
                    'first_contact_at' => $lead->created_at ?? now(),
                    'last_contact_at' => $lead->created_at ?? now(),
                ]);
        } elseif ($phone !== null) {
            $customer = $this->claimPhone($customer, $phone);
        }

        if (blank($customer->name) && filled($lead->customer_name)) {
            $customer->forceFill(['name' => $lead->customer_name])->save();
        }

        if ($lead->customer_id !== $customer->id) {
            $lead->forceFill(['customer_id' => $customer->id])->saveQuietly();
        }

        return $customer;
    }

    public function claimPhone(Customer $customer, ?string $phone): Customer
    {
        $key = $this->phoneKey($phone);

        if ($key === null || $customer->phone_key === $key) {
            return $customer;
        }

        if ($customer->phone_key !== null) {
            return $customer;
        }

        $holder = Customer::where('team_id', $customer->team_id)->where('phone_key', $key)->first();

        if ($holder !== null && $holder->id !== $customer->id) {
            return $this->merge(from: $customer, into: $holder);
        }

        try {
            $customer->forceFill(['phone' => $phone, 'phone_key' => $key])->save();
        } catch (UniqueConstraintViolationException) {

            $holder = Customer::where('team_id', $customer->team_id)->where('phone_key', $key)->first();

            return $holder !== null ? $this->merge(from: $customer, into: $holder) : $customer->refresh();
        }

        return $customer;
    }

    public function merge(Customer $from, Customer $into): Customer
    {
        if ($from->id === $into->id || $from->team_id === null || $from->team_id !== $into->team_id) {
            return $into;
        }

        DB::transaction(function () use ($from, $into) {

            $conversationIds = Conversation::where('customer_id', $from->id)->pluck('id')->all();
            $leadIds = Lead::where('customer_id', $from->id)->pluck('id')->all();

            $dealIds = Deal::where('team_id', $into->team_id)->where('customer_id', $from->id)->pluck('id')->all();
            $taskIds = Task::where('team_id', $into->team_id)->where('customer_id', $from->id)->pluck('id')->all();

            Conversation::where('customer_id', $from->id)->update(['customer_id' => $into->id]);
            Lead::where('customer_id', $from->id)->update(['customer_id' => $into->id]);

            Deal::whereIn('id', $dealIds)->update(['customer_id' => $into->id]);
            Task::whereIn('id', $taskIds)->update(['customer_id' => $into->id]);

            $older = $this->startedEarlier($from, $into) ? $from : $into;

            $fromPhone = $from->phone;
            $fromPhoneKey = $from->phone_key;

            $from->delete();

            $into->forceFill([
                'name' => filled($into->name) ? $into->name : $from->name,
                'phone' => $into->phone ?: $fromPhone,
                'phone_key' => $into->phone_key ?: $fromPhoneKey,
                'note' => filled($into->note) ? $into->note : $from->note,

                'first_contact_at' => $this->earliest($into->first_contact_at, $from->first_contact_at),
                'last_contact_at' => $this->latest($into->last_contact_at, $from->last_contact_at),
                'source' => $older->source ?: ($into->source ?: $from->source),
                'tags' => array_values(array_unique(array_merge($into->tags ?? [], $from->tags ?? []))) ?: null,

                'status' => $into->status !== 'lead' ? $into->status : $from->status,
            ])->save();

            CustomerMerge::create([
                'user_id' => $into->user_id,

                'team_id' => $into->team_id,
                'from_customer_id' => $from->id,
                'into_customer_id' => $into->id,
                'moved_conversation_ids' => $conversationIds,
                'moved_lead_ids' => $leadIds,
                'moved_deal_ids' => $dealIds,
                'moved_task_ids' => $taskIds,
            ]);
        });

        return $into->refresh();
    }

    public function undoMerge(CustomerMerge $merge): ?Customer
    {
        if ($merge->undone_at !== null || $merge->created_at->lt(now()->subHours(CustomerMerge::UNDO_WINDOW_HOURS))) {
            return null;
        }

        $from = $merge->fromCustomer;
        $into = $merge->intoCustomer;

        if ($from === null || $into === null) {
            return null;
        }

        return DB::transaction(function () use ($merge, $from) {
            $from->restore();

            $key = $this->phoneKey($from->phone);
            if ($key !== null && ! Customer::where('team_id', $from->team_id)->where('phone_key', $key)->exists()) {
                $from->forceFill(['phone_key' => $key])->save();
            }

            Conversation::whereIn('id', $merge->moved_conversation_ids ?? [])->update(['customer_id' => $from->id]);
            Lead::whereIn('id', $merge->moved_lead_ids ?? [])->update(['customer_id' => $from->id]);
            Deal::whereIn('id', $merge->moved_deal_ids ?? [])->update(['customer_id' => $from->id]);
            Task::whereIn('id', $merge->moved_task_ids ?? [])->update(['customer_id' => $from->id]);

            $merge->forceFill(['undone_at' => now()])->save();

            return $from;
        });
    }

    public function phoneKey(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone) ?? '';
        $length = strlen($digits);

        return $length >= self::MIN_DIGITS && $length <= self::MAX_DIGITS
            ? substr($digits, -self::MIN_DIGITS)
            : null;
    }

    private function phoneFor(Conversation $conversation): ?string
    {
        $type = $conversation->channel?->type;

        if ($type !== null && in_array($type, self::PHONE_CHANNELS, true)) {
            $phone = $conversation->external_user_id;

            if ($this->phoneKey($phone) !== null) {
                return $phone;
            }
        }

        return $conversation->leads()->whereNotNull('phone')->orderBy('id')->value('phone');
    }

    private function findByPhone(?int $teamId, ?string $phone): ?Customer
    {
        $key = $this->phoneKey($phone);

        return $key === null || $teamId === null
            ? null
            : Customer::where('team_id', $teamId)->where('phone_key', $key)->first();
    }

    private function open(int $userId, Conversation $conversation, ?string $phone): Customer
    {
        $key = $this->phoneKey($phone);
        $team = $conversation->agent?->team_id;

        try {
            return Customer::forceCreate([
                'user_id' => $userId,

                'team_id' => $team,

                'crm_workspace_id' => CrmWorkspace::defaultFor(User::findOrFail($userId), $team)->id,
                'name' => $conversation->external_user_name,
                'phone' => $key !== null ? $phone : null,
                'phone_key' => $key,
                'source' => $conversation->channel?->type,
                'first_contact_at' => $conversation->created_at ?? now(),
                'last_contact_at' => $conversation->last_message_at ?? $conversation->created_at ?? now(),
            ]);
        } catch (UniqueConstraintViolationException) {

            return Customer::where('team_id', $team)->where('phone_key', $key)->firstOrFail();
        }
    }

    private function touchContact(Customer $customer, Conversation $conversation): void
    {
        $started = $conversation->created_at;
        $last = $conversation->last_message_at ?? $conversation->created_at;

        $first = $this->earliest($customer->first_contact_at, $started);
        $latest = $this->latest($customer->last_contact_at, $last);

        if ($first?->equalTo($customer->first_contact_at) && $latest?->equalTo($customer->last_contact_at)) {
            return;
        }

        $customer->forceFill(['first_contact_at' => $first, 'last_contact_at' => $latest])->save();
    }

    private function startedEarlier(Customer $a, Customer $b): bool
    {
        if ($a->first_contact_at === null || $b->first_contact_at === null) {
            return $a->first_contact_at === null;
        }

        return $a->first_contact_at->lessThan($b->first_contact_at);
    }

    private function earliest(mixed $a, mixed $b): mixed
    {
        if ($a === null || $b === null) {
            return $a ?? $b;
        }

        return $a->lessThan($b) ? $a : $b;
    }

    private function latest(mixed $a, mixed $b): mixed
    {
        if ($a === null || $b === null) {
            return $a ?? $b;
        }

        return $a->greaterThan($b) ? $a : $b;
    }
}
