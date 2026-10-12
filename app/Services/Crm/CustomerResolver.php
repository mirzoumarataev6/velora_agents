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

/**
 * Decides which real person a thread or a lead belongs to.
 *
 * WHAT THE DATA ACTUALLY SUPPORTS. Only two of the five channel drivers hand us
 * something a human could dial: WhatsApp Cloud (`from`) and Green API (`chatId`
 * before the @) are both plain msisdns. Telegram gives an opaque numeric user
 * id and Instagram an IGSID — neither is a phone number and neither is
 * comparable with anything from another app.
 *
 * So identity is: the phone number when we have one, and otherwise the thread
 * itself. A Telegram-only customer who never leaves a number simply stays their
 * own profile, and there is no honest way around that — the only other shared
 * attribute is the display name, and merging "Азиз" with "Азиз" would silently
 * fuse two strangers into one customer with one order history. That is a worse
 * failure than an unmerged duplicate, because it is invisible and irreversible
 * in the owner's eyes, so name matching is not attempted anywhere here.
 *
 * WHAT MAKES IT PAY OFF ANYWAY is that identification is retroactive. The
 * moment a number turns up on a thread that had none — the customer types it,
 * or the agent captures it into a lead — the profile claims that number, and if
 * another profile already held it the two collapse into one, bringing both
 * channels' history with them.
 */
class CustomerResolver
{
    /** Channel types whose external_user_id IS the phone number. */
    public const PHONE_CHANNELS = ['whatsapp', 'greenapi'];

    /**
     * Fewest and most digits that can be a phone number, matching
     * PhoneNumberDetector: below nine it is a price or a house number, above
     * fifteen it is an id.
     */
    private const MIN_DIGITS = 9;

    private const MAX_DIGITS = 15;

    /**
     * The customer this thread belongs to, creating or merging profiles as the
     * evidence requires. Idempotent: calling it again on a linked conversation
     * returns the same customer and only refreshes the timestamps.
     *
     * Playground threads have no customer — that is the owner talking to their
     * own agent, and a CRM that files the owner as their own first lead is
     * broken on the day it ships.
     */
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
            $customer = $this->findByPhone($userId, $phone) ?? $this->open($userId, $conversation, $phone);
        } elseif ($phone !== null) {
            // The thread already had a profile and has now produced a number:
            // this is the retroactive merge path.
            $customer = $this->claimPhone($customer, $phone);
        }

        if ($conversation->customer_id !== $customer->id) {
            // Quietly: the observers that call this listen for saves, and the
            // link is bookkeeping, not a change to the thread itself.
            $conversation->forceFill(['customer_id' => $customer->id])->saveQuietly();
        }

        $this->touchContact($customer, $conversation);

        return $customer;
    }

    /**
     * File a captured lead against a customer, using the number the agent
     * managed to get out of the conversation.
     *
     * This is where most merges actually happen: a customer writes from
     * Telegram for weeks as an opaque id, then leaves a phone number when they
     * finally order, and their WhatsApp history joins the same profile.
     */
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
                // A lead with neither a thread nor a number cannot be attached
                // to anyone. Left unlinked rather than given a profile of its
                // own, which would be a customer the owner can never contact.
                return null;
            }

            $customer = $this->findByPhone($userId, $phone)
                // forceCreate: user_id is deliberately not mass-assignable on
                // Customer, so no request payload can ever file a customer
                // under another account.
                ?? Customer::forceCreate([
                    'user_id' => $userId,
                    // Explicit, not left to StampsTeam's own user_id
                    // fallback: this runs in the background (LeadObserver,
                    // off a webhook), so current_team_id is whichever team
                    // the owner happens to be looking at right now, not
                    // necessarily the team that owns the agent this lead
                    // came through. $lead->agent->team_id is already
                    // resolved and already in scope. Null if the relation
                    // is somehow missing — StampsTeam falls back exactly as
                    // today in that case.
                    'team_id' => $lead->agent?->team_id,
                    'crm_workspace_id' => CrmWorkspace::defaultFor(User::findOrFail($userId))->id,
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

    /**
     * Give a profile a phone number, folding in whoever already held it.
     *
     * Returns the surviving customer, which may not be the one passed in — the
     * older profile wins, so the customer's history keeps its original start
     * date rather than appearing to begin the day they mentioned their number.
     */
    public function claimPhone(Customer $customer, ?string $phone): Customer
    {
        $key = $this->phoneKey($phone);

        if ($key === null || $customer->phone_key === $key) {
            return $customer;
        }

        // Already identified as somebody else's number. Not a merge — an
        // attempt to reassign a profile, which is only ever a data error.
        if ($customer->phone_key !== null) {
            return $customer;
        }

        $holder = Customer::where('user_id', $customer->user_id)->where('phone_key', $key)->first();

        if ($holder !== null && $holder->id !== $customer->id) {
            return $this->merge(from: $customer, into: $holder);
        }

        try {
            $customer->forceFill(['phone' => $phone, 'phone_key' => $key])->save();
        } catch (UniqueConstraintViolationException) {
            // Two webhooks resolved the same new number at once. The other one
            // won the unique index; join it rather than fail the message.
            $holder = Customer::where('user_id', $customer->user_id)->where('phone_key', $key)->first();

            return $holder !== null ? $this->merge(from: $customer, into: $holder) : $customer->refresh();
        }

        return $customer;
    }

    /**
     * Fold one profile into another: threads, leads, tags and the contact
     * window all move, and the emptied profile is soft-deleted rather than
     * gone — see undoMerge() for reversing exactly this.
     *
     * Also the implementation behind the manual "these two are the same person"
     * action, for the customers the data could never match on its own.
     */
    public function merge(Customer $from, Customer $into): Customer
    {
        if ($from->id === $into->id || $from->user_id !== $into->user_id) {
            return $into;
        }

        DB::transaction(function () use ($from, $into) {
            // Captured before the move so undoMerge() knows exactly which
            // rows were this profile's own — not just whatever `into` ends
            // up holding, which after the merge also includes its OWN
            // pre-existing history and cannot be told apart from $from's by
            // the customer_id column alone any more.
            $conversationIds = Conversation::where('customer_id', $from->id)->pluck('id')->all();
            $leadIds = Lead::where('customer_id', $from->id)->pluck('id')->all();
            $dealIds = Deal::where('user_id', $into->user_id)->where('customer_id', $from->id)->pluck('id')->all();
            $taskIds = Task::where('user_id', $into->user_id)->where('customer_id', $from->id)->pluck('id')->all();

            Conversation::where('customer_id', $from->id)->update(['customer_id' => $into->id]);
            Lead::where('customer_id', $from->id)->update(['customer_id' => $into->id]);
            // Deals and reminders are customer history too. Move them before
            // deleting the duplicate profile or their customer FK would erase
            // the very purchase trail the merge is supposed to preserve.
            Deal::whereIn('id', $dealIds)->update(['customer_id' => $into->id]);
            Task::whereIn('id', $taskIds)->update(['customer_id' => $into->id]);

            // Acquisition is a fact about the FIRST contact, so the channel
            // recorded is the one belonging to whichever profile started earlier.
            $older = $this->startedEarlier($from, $into) ? $from : $into;
            // Read before $from is touched: once it is deleted, Customer::
            // booted()'s own hook has already nulled its phone_key, so this
            // is the only chance to carry the number forward.
            $fromPhone = $from->phone;
            $fromPhoneKey = $from->phone_key;

            // Freed BEFORE $into claims it, not after: with both still
            // holding the same phone_key at once, $into's save() below would
            // collide with the very row it is about to replace in the
            // unique index. Only matters when $from — not $into — is the
            // one with a real number; CustomerController::merge()'s own
            // candidate list never offers one, but the service method itself
            // must be correct regardless of which side is called with which.
            $from->delete();

            $into->forceFill([
                'name' => filled($into->name) ? $into->name : $from->name,
                'phone' => $into->phone ?: $fromPhone,
                'phone_key' => $into->phone_key ?: $fromPhoneKey,
                'note' => filled($into->note) ? $into->note : $from->note,
                // Whichever profile was opened first is when this person became
                // a customer of the business.
                'first_contact_at' => $this->earliest($into->first_contact_at, $from->first_contact_at),
                'last_contact_at' => $this->latest($into->last_contact_at, $from->last_contact_at),
                'source' => $older->source ?: ($into->source ?: $from->source),
                'tags' => array_values(array_unique(array_merge($into->tags ?? [], $from->tags ?? []))) ?: null,
                // The owner's judgement, kept: a profile already marked won or
                // lost must not be reset to 'lead' by a merge.
                'status' => $into->status !== 'lead' ? $into->status : $from->status,
            ])->save();

            CustomerMerge::create([
                'user_id' => $into->user_id,
                // Explicit, not left to StampsTeam's own user_id fallback:
                // this can run in the background (claimPhone(), off a
                // webhook resolving a thread's number), where
                // current_team_id is whichever team the owner happens to
                // be looking at right now, not necessarily the team that
                // owns $into. $into->team_id is already resolved and
                // already in scope.
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

    /**
     * Reverses one merge: restores the absorbed profile and moves back
     * exactly the rows merge() recorded as having come from it — never
     * `into`'s own, which by now share the same customer_id and cannot be
     * told apart any other way.
     *
     * Deliberately NOT a full reversal. `into` may have had its name, phone,
     * tags, note or status filled in from `from` during the merge — those
     * stay. Unpicking which of `into`'s current field values originated from
     * `from` would need a snapshot of `into` too, and the one thing this
     * action exists to guarantee safely is that nobody's conversation and
     * order history stays misattributed — not that every trace of the merge
     * vanishes. See the class docblock on CustomerMerge's migration.
     *
     * Silently a no-op outside the window or once already undone — the
     * caller (CustomerController) only ever offers this button while
     * CustomerMerge::undoable() says it is still live, so reaching here
     * otherwise is a stale request, not a real one.
     */
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

            // Only if nobody has since claimed it — a number this profile no
            // longer holds is not this undo's problem to fight over.
            $key = $this->phoneKey($from->phone);
            if ($key !== null && ! Customer::where('user_id', $from->user_id)->where('phone_key', $key)->exists()) {
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

    /**
     * The match key: the last nine digits of the number.
     *
     * Nine because that is a bare Tajik mobile, so "900112233" and
     * "+992900112233" — the same person, typed twice — land on one key. Two
     * different numbers colliding would need to share nine trailing digits and
     * the same owner's address book, which is not a risk worth an unmerged
     * profile on every customer who omits their country code.
     */
    public function phoneKey(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone) ?? '';
        $length = strlen($digits);

        return $length >= self::MIN_DIGITS && $length <= self::MAX_DIGITS
            ? substr($digits, -self::MIN_DIGITS)
            : null;
    }

    /** The number this thread reveals, if its channel reveals one at all. */
    private function phoneFor(Conversation $conversation): ?string
    {
        $type = $conversation->channel?->type;

        if ($type !== null && in_array($type, self::PHONE_CHANNELS, true)) {
            $phone = $conversation->external_user_id;

            if ($this->phoneKey($phone) !== null) {
                return $phone;
            }
        }

        // Otherwise the only number that can appear is one the agent captured.
        return $conversation->leads()->whereNotNull('phone')->orderBy('id')->value('phone');
    }

    private function findByPhone(int $userId, ?string $phone): ?Customer
    {
        $key = $this->phoneKey($phone);

        return $key === null
            ? null
            : Customer::where('user_id', $userId)->where('phone_key', $key)->first();
    }

    private function open(int $userId, Conversation $conversation, ?string $phone): Customer
    {
        $key = $this->phoneKey($phone);

        try {
            return Customer::forceCreate([
                'user_id' => $userId,
                // Explicit, not left to StampsTeam's own user_id fallback:
                // this runs in the background (ConversationObserver, off a
                // webhook), so current_team_id is whichever team the owner
                // happens to be looking at right now, not necessarily the
                // team that owns the agent this thread came through.
                // $conversation->agent->team_id is already resolved and
                // already in scope. Null if the relation is somehow
                // missing — StampsTeam falls back exactly as today in that
                // case.
                'team_id' => $conversation->agent?->team_id,
                'crm_workspace_id' => CrmWorkspace::defaultFor(User::findOrFail($userId))->id,
                'name' => $conversation->external_user_name,
                'phone' => $key !== null ? $phone : null,
                'phone_key' => $key,
                'source' => $conversation->channel?->type,
                'first_contact_at' => $conversation->created_at ?? now(),
                'last_contact_at' => $conversation->last_message_at ?? $conversation->created_at ?? now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return Customer::where('user_id', $userId)->where('phone_key', $key)->firstOrFail();
        }
    }

    /** Keeps the profile's contact window covering every thread hanging off it. */
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

    /** Whether $a's history starts before $b's; an unknown start is treated as older. */
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
