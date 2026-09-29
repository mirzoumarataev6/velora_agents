<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Services\Crm\CustomerResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;

/**
 * The customer profile: everything about one person, reached from a deal
 * (or from search) rather than browsed as its own directory.
 *
 * A view over conversations and leads — nothing here duplicates that state, it
 * groups it by the person the CustomerResolver decided is behind it.
 */
class CustomerController extends Controller
{
    /** How much of the last message a profile's thread list previews. */
    protected const EXCERPT = 140;

    /**
     * Does this phone number already belong to somebody? Backs the live check
     * in the "+ Новый клиент" fields of the create-deal modal — typing a
     * number that matches an existing profile should surface that profile
     * before the owner accidentally opens a duplicate one.
     *
     * Same match rule as everywhere else in the CRM (CustomerResolver's
     * phone_key), so a number this endpoint says "no match" for cannot turn
     * around and merge into someone on submit, or the other way round.
     */
    public function lookup(Request $request, CustomerResolver $resolver): JsonResponse
    {
        $key = $resolver->phoneKey($request->query('phone'));

        $customer = $key === null
            ? null
            : Customer::ownedBy($request->user())->where('phone_key', $key)->first(['id', 'name']);

        return response()->json([
            'customer' => $customer === null ? null : ['id' => $customer->id, 'name' => $customer->displayName()],
        ]);
    }

    /** Search the owner's full customer book for the deal creation combobox. */
    public function search(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:120']]);
        $term = trim($data['q'] ?? '');

        $customers = Customer::ownedBy($request->user())
            ->when($term !== '', fn ($query) => $query->search($term))
            ->orderByDesc('last_contact_at')->orderByDesc('id')->limit(20)->get();

        return response()->json([
            'customers' => $customers->map(fn (Customer $customer) => [
                'id' => $customer->id,
                'name' => $customer->displayName(),
                'phone' => $customer->phone,
            ]),
        ]);
    }

    /**
     * One person, everything about them: every channel they wrote from, the
     * whole cross-channel transcript in one timeline, and their orders.
     */
    public function show(Request $request, Customer $customer): View
    {
        $this->authorize('view', $customer);

        $customer->load([
            'conversations' => fn ($q) => $q->orderByDesc('last_message_at'),
            'conversations.channel:id,type',
            'conversations.agent:id,name,slug',
            'leads' => fn ($q) => $q->latest('id'),
            'leads.agent:id,name,slug',
            'deals' => fn ($q) => $q->orderByDesc('id'),
            // Open tasks first, soonest due first: a profile should answer
            // "what do I owe this person" before "what did I already do".
            'tasks' => fn ($q) => $q->orderByRaw('done_at is not null')
                ->orderByRaw('due_at is null')
                ->orderBy('due_at'),
        ]);

        return view('crm.customers.show', [
            'i18nExtraNamespaces' => ['crm'],
            'customer' => $customer,
            'timeline' => $this->timeline($customer),
            // Candidates for a manual merge: same owner, and no phone number of
            // their own, since a profile that HAS one was already matched
            // automatically and joining it by hand would override real evidence.
            'mergeCandidates' => Customer::ownedBy($request->user())
                ->whereKeyNot($customer->id)
                ->whereNull('phone_key')
                ->orderByDesc('last_contact_at')
                ->limit(50)
                ->get(['id', 'name', 'source', 'last_contact_at']),
        ]);
    }

    /**
     * A partial update: every field is `sometimes`, so a caller that only
     * means to touch one of them — the deal panel's own status quick-select,
     * for instance — can send just that key without the other three being
     * read as "clear this", which an unconditional write from a form that
     * simply didn't include them used to do.
     */
    public function update(Request $request, Customer $customer): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $customer);

        $data = $request->validate([
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'required', 'string', 'in:'.implode(',', Customer::STATUSES)],
            'tags' => ['sometimes', 'nullable', 'string', 'max:500'],
            'note' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);

        $updates = [];

        if (array_key_exists('name', $data)) {
            $updates['name'] = $data['name'];
        }

        if (array_key_exists('status', $data)) {
            $updates['status'] = $data['status'];
        }

        if (array_key_exists('tags', $data)) {
            $updates['tags'] = $this->parseTags($data['tags']);
        }

        if (array_key_exists('note', $data)) {
            $updates['note'] = $data['note'];
        }

        $customer->update($updates);

        if ($request->wantsJson()) {
            return response()->json(['status' => $customer->status]);
        }

        return back()->with('status', __('crm.customers.saved'));
    }

    /**
     * "These two are the same person."
     *
     * The escape hatch for what the data cannot prove: a Telegram customer and
     * an Instagram customer who never left a number share nothing the system
     * could match on, and only the owner knows they are one person.
     */
    public function merge(Request $request, Customer $customer, CustomerResolver $resolver): RedirectResponse
    {
        $this->authorize('update', $customer);

        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'different:'.$customer->id],
        ]);

        $other = Customer::ownedBy($request->user())->find($data['customer_id']);

        if ($other === null) {
            return back()->withErrors(['customer_id' => __('crm.customers.merge_not_found')]);
        }

        // This page survives the merge, so it is the one that absorbs the other.
        $resolver->merge(from: $other, into: $customer);

        return redirect()
            ->route('crm.customers.show', $customer)
            ->with('status', __('crm.customers.merged'));
    }

    /**
     * Every message this person has sent or been sent, across every channel,
     * in one chronological list.
     *
     * This is the whole point of the customer record: the owner reads one story
     * instead of opening three apps and reconstructing the order of events.
     *
     * @return list<array<string, mixed>>
     */
    protected function timeline(Customer $customer): LengthAwarePaginator
    {
        return Message::whereHas('conversation', fn ($query) => $query->where('customer_id', $customer->id))
            ->with('conversation.channel:id,type')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(30, ['id', 'conversation_id', 'role', 'content', 'created_at'], 'timeline_page')
            ->appends(request()->except('timeline_page'))
            ->through(fn (Message $m) => [
                'id' => $m->id,
                'conversation_id' => $m->conversation_id,
                'channel' => $m->conversation?->channel?->type,
                'role' => $m->role,
                'content' => $m->content,
                'at' => $m->created_at,
            ]);
    }

    /** Free-typed, comma separated — the fastest thing to enter and to skim. */
    protected function parseTags(?string $raw): ?array
    {
        $tags = collect(explode(',', (string) $raw))
            ->map(fn (string $tag) => Str::squish($tag))
            ->filter()
            ->unique()
            ->take(20)
            ->values()
            ->all();

        return $tags ?: null;
    }

    /** Trimmed preview of a lead or message, for the profile's summary rows. */
    public static function excerpt(?string $text): string
    {
        return Str::limit(Str::squish((string) $text), self::EXCERPT);
    }
}
