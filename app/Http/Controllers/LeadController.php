<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Lead;
use App\Services\Haggling\HaggleDecisionRelay;
use App\Services\Haggling\HagglingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\View\View;

class LeadController extends Controller
{
    /** Lead workflow states the owner can move a lead between by hand. */
    protected const STATUSES = ['new', 'in_progress', 'done'];

    /**
     * The two halves of this panel, and they are genuinely different work.
     *
     * An order is a RECORD: somebody wants something, ring them back when you
     * can. A price request is a DECISION with a customer sitting in a live chat
     * waiting on it, and it expires in practice the moment they give up. Mixed
     * into one list the second kind gets buried under the first — which is
     * exactly what a badge on a tab is for.
     */
    protected const VIEWS = ['orders', 'haggle'];

    /**
     * Statuses each view can be sliced by. Deliberately different lists: 'new'
     * is meaningless for a price request (the assistant files it already
     * pending), and the pending state is unreachable for an order.
     */
    protected const VIEW_STATUSES = [
        'orders' => ['new', 'in_progress', 'done'],
        'haggle' => [HagglingService::STATUS_PENDING, 'in_progress', 'done'],
    ];

    public function index(Request $request): View
    {
        $view = in_array($request->query('view'), self::VIEWS, true) ? $request->query('view') : self::VIEWS[0];
        $status = $request->query('status');
        $agentId = $request->query('agent');
        $term = trim((string) $request->query('q')) ?: null;

        $statuses = self::VIEW_STATUSES[$view];

        $leads = $this->scoped($request)
            // The tab IS a filter, and the strict one: a price request must
            // never surface in the orders list, whatever else is narrowing it.
            ->where('leads.type', $view === 'haggle' ? HagglingService::TYPE : 'order')
            ->when(in_array($status, $statuses, true), fn ($q) => $q->where('leads.status', $status))
            ->when($agentId, fn ($q) => $q->where('leads.agent_id', $agentId))
            // Who they are, how to reach them, and what they asked for — the
            // three things an owner actually remembers a lead by.
            ->when($term, fn ($q, $needle) => $q->where(fn ($w) => $w
                ->where('leads.customer_name', 'like', "%{$needle}%")
                ->orWhere('leads.phone', 'like', "%{$needle}%")
                ->orWhere('leads.contact', 'like', "%{$needle}%")
                ->orWhere('leads.items', 'like', "%{$needle}%")
            ))
            ->with('agent:id,name,slug')
            ->latest('leads.created_at')
            ->paginate(20)
            // Not withQueryString(): that would carry `fragment=1` into the
            // pager links, so page 2 from a live search would navigate the
            // browser to a bare, chrome-less block of HTML.
            ->appends(Arr::except($request->query(), ['fragment', 'page']));

        $shared = [
            'leads' => $leads,
            'activeView' => $view,
            'activeStatus' => in_array($status, $statuses, true) ? $status : null,
            'activeAgent' => $agentId,
            'activeSearch' => $term,
            'statusOptions' => $statuses,
        ];

        // The live search asks for just the result block, rendered from the same
        // partial the full page uses. It deliberately skips both the seen-marking
        // below (already done when the page loaded — re-running it on every
        // keystroke would be a write per character) and the agent list, which the
        // fragment does not contain.
        if ($request->boolean('fragment')) {
            return view('leads.partials.results', $shared);
        }

        // Reviewing the list is the natural moment to clear the unread bell.
        $this->markAllSeen($request);

        return view('leads.index', $shared + [
            'agents' => $request->user()->agents()->orderBy('name')->get(['id', 'name']),
            // Drives the badge on the Торг tab. Unfiltered on purpose: a
            // pending decision the current filter hides is still a customer
            // waiting, and hiding the count would be how it gets missed.
            'pendingHaggleCount' => $this->pendingHaggleQuery($request)->count(),
        ]);
    }

    /**
     * Lightweight JSON feed for the in-app notification bell: the unseen count
     * plus the few most recent leads. Polled on an interval, so it stays cheap.
     */
    public function poll(Request $request): JsonResponse
    {
        $recent = $this->scoped($request)
            ->with('agent:id,name')
            ->latest('leads.created_at')
            ->limit(6)
            ->get()
            ->map(fn (Lead $lead) => [
                'id' => $lead->id,
                'customer_name' => $lead->customer_name,
                'phone' => $lead->phone,
                'contact' => $lead->contact,
                'items' => $lead->items,
                'type' => $lead->type,
                'requested_price' => $lead->requested_price,
                'agent_name' => $lead->agent?->name,
                'seen' => $lead->seen_at !== null,
                'created_at' => $lead->created_at?->toIso8601String(),
            ]);

        // Escalated conversations ride the same bell as leads: both are "a human
        // is needed here", and a second badge would just compete with the first.
        $escalations = Conversation::ownedBy($request->user())
            ->needsHumanUnattended()
            ->with('agent:id,name')
            ->orderByDesc('needs_human_at')
            ->limit(6)
            ->get()
            ->map(fn (Conversation $c) => [
                'id' => $c->id,
                'customer' => $c->external_user_name ?: ($c->external_user_id ?? '—'),
                'summary' => $c->needs_human_summary,
                'signal' => $c->needs_human_signal,
                'agent_name' => $c->agent?->name,
                'at' => $c->needs_human_at?->toIso8601String(),
            ]);

        $pendingPrices = $this->pendingHaggleQuery($request)->count();
        $unseenLeads = $this->scoped($request)->whereNull('leads.seen_at')->count();
        $unattended = Conversation::ownedBy($request->user())->needsHumanUnattended()->count();

        return response()->json([
            'count' => $unseenLeads + $unattended,
            'recent' => $recent,
            'escalations' => $escalations,
            // Surfaced separately from the badge total so the bell can say what
            // KIND of attention is wanted: a price decision is the one item
            // here with someone waiting on the other end of it right now.
            'pending_prices' => $pendingPrices,
        ]);
    }

    public function markSeen(Request $request): JsonResponse
    {
        $this->markAllSeen($request);

        // Not zero: escalations are cleared by OPENING the conversation, not by
        // dismissing the bell, so any still-unattended ones must keep the badge
        // lit rather than be silently marked as dealt with.
        return response()->json([
            'count' => Conversation::ownedBy($request->user())->needsHumanUnattended()->count(),
        ]);
    }

    /**
     * Approve a price the assistant was not allowed to give, and tell the
     * customer so.
     *
     * The relay is part of the decision, not a follow-up: the customer was told
     * "я уточню у менеджера и вернусь к вам", and an approval that never
     * reaches the chat loses the very sale it just agreed to.
     */
    public function approvePrice(Request $request, Lead $lead, HaggleDecisionRelay $relay): JsonResponse|RedirectResponse
    {
        $this->authorize('update', $lead);

        $data = $request->validate([
            // Absent means "at the price they asked for". Present means the
            // owner is granting a different number than the one requested.
            'price' => ['nullable', 'numeric', 'min:0.01', 'max:99999999'],
        ]);

        abort_unless($lead->awaitsPriceApproval(), 422);

        $relay->approve($lead, isset($data['price']) ? (float) $data['price'] : null);

        return $this->decisionResponse($request, $lead, __('leads.haggle.approved'));
    }

    /**
     * Decline the requested price — optionally naming one the owner WOULD do,
     * which hands the conversation back as a counter-offer rather than a wall.
     */
    public function declinePrice(Request $request, Lead $lead, HaggleDecisionRelay $relay): JsonResponse|RedirectResponse
    {
        $this->authorize('update', $lead);

        $data = $request->validate([
            'counter_price' => ['nullable', 'numeric', 'min:0.01', 'max:99999999'],
        ]);

        abort_unless($lead->awaitsPriceApproval(), 422);

        $relay->decline($lead, isset($data['counter_price']) ? (float) $data['counter_price'] : null);

        return $this->decisionResponse($request, $lead, __('leads.haggle.declined'));
    }

    protected function decisionResponse(Request $request, Lead $lead, string $message): JsonResponse|RedirectResponse
    {
        if ($request->wantsJson()) {
            return response()->json([
                'status' => $lead->status,
                'approved_price' => $lead->approved_price,
                'message' => $message,
            ]);
        }

        return back()->with('status', $message);
    }

    public function update(Request $request, Lead $lead): JsonResponse|RedirectResponse
    {
        $this->authorize('update', $lead);

        $data = $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', self::STATUSES)],
        ]);

        $lead->update(['status' => $data['status']]);

        if ($request->wantsJson()) {
            return response()->json(['status' => $lead->status]);
        }

        return back()->with('status', __('leads.index.updated'));
    }

    public function destroy(Request $request, Lead $lead): JsonResponse|RedirectResponse
    {
        $this->authorize('delete', $lead);

        $lead->delete();

        if ($request->wantsJson()) {
            return response()->json(['deleted' => true]);
        }

        return back()->with('status', __('leads.index.deleted'));
    }

    /**
     * Base query for leads belonging to the authenticated owner's agents.
     */
    protected function scoped(Request $request)
    {
        return Lead::whereIn('leads.agent_id', $request->user()->agents()->select('id'));
    }

    /** Price requests still waiting on this owner, across every agent. */
    protected function pendingHaggleQuery(Request $request)
    {
        return $this->scoped($request)
            ->where('leads.type', HagglingService::TYPE)
            ->where('leads.status', HagglingService::STATUS_PENDING);
    }

    protected function markAllSeen(Request $request): void
    {
        $this->scoped($request)->whereNull('leads.seen_at')->update(['seen_at' => now()]);
    }
}
