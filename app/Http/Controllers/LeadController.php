<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\View\View;

class LeadController extends Controller
{
    /** Lead workflow states the owner can move a lead between. */
    protected const STATUSES = ['new', 'in_progress', 'done'];

    public function index(Request $request): View
    {
        $status = $request->query('status');
        $agentId = $request->query('agent');
        $term = trim((string) $request->query('q')) ?: null;

        $leads = $this->scoped($request)
            ->when(in_array($status, self::STATUSES, true), fn ($q) => $q->where('leads.status', $status))
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
            'activeStatus' => in_array($status, self::STATUSES, true) ? $status : null,
            'activeAgent' => $agentId,
            'activeSearch' => $term,
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

        $unseenLeads = $this->scoped($request)->whereNull('leads.seen_at')->count();
        $unattended = Conversation::ownedBy($request->user())->needsHumanUnattended()->count();

        return response()->json([
            'count' => $unseenLeads + $unattended,
            'recent' => $recent,
            'escalations' => $escalations,
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

    protected function markAllSeen(Request $request): void
    {
        $this->scoped($request)->whereNull('leads.seen_at')->update(['seen_at' => now()]);
    }
}
