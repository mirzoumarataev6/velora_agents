<?php

namespace App\Http\Controllers;

use App\Models\Agent;
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
    protected const STATUSES = ['new', 'in_progress', 'done'];

    protected const VIEWS = ['orders', 'haggle'];

    protected const VIEW_STATUSES = [
        'orders' => ['new', 'in_progress', 'done'],
        'haggle' => [HagglingService::STATUS_PENDING, 'in_progress', 'done'],
    ];

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Lead::class);

        $view = in_array($request->query('view'), self::VIEWS, true) ? $request->query('view') : self::VIEWS[0];
        $status = $request->query('status');
        $agentId = $request->query('agent');
        $term = trim((string) $request->query('q')) ?: null;

        $statuses = self::VIEW_STATUSES[$view];

        $leads = $this->scoped($request)

            ->where('leads.type', $view === 'haggle' ? HagglingService::TYPE : 'order')
            ->when(in_array($status, $statuses, true), fn ($q) => $q->where('leads.status', $status))
            ->when($agentId, fn ($q) => $q->where('leads.agent_id', $agentId))

            ->when($term, fn ($q, $needle) => $q->where(fn ($w) => $w
                ->where('leads.customer_name', 'like', "%{$needle}%")
                ->orWhere('leads.phone', 'like', "%{$needle}%")
                ->orWhere('leads.contact', 'like', "%{$needle}%")
                ->orWhere('leads.items', 'like', "%{$needle}%")
            ))
            ->with('agent:id,name,slug')
            ->latest('leads.created_at')
            ->paginate(20)

            ->appends(Arr::except($request->query(), ['fragment', 'page']));

        $shared = [
            'leads' => $leads,
            'activeView' => $view,
            'activeStatus' => in_array($status, $statuses, true) ? $status : null,
            'activeAgent' => $agentId,
            'activeSearch' => $term,
            'statusOptions' => $statuses,
        ];

        if ($request->boolean('fragment')) {
            return view('leads.partials.results', $shared);
        }

        $this->markAllSeen($request);

        return view('leads.index', $shared + [
            'agents' => Agent::ownedBy($request->user())->orderBy('name')->get(['id', 'name']),

            'pendingHaggleCount' => $this->pendingHaggleQuery($request)->count(),
        ]);
    }

    public function poll(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Lead::class);

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

        $escalations = Conversation::ownedBy($request->user())
            ->when(! $request->user()->can('conversations.view'), fn ($q) => $q->whereRaw('1 = 0'))->needsHumanUnattended()
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
        $unattended = Conversation::ownedBy($request->user())->when(! $request->user()->can('conversations.view'), fn ($q) => $q->whereRaw('1 = 0'))->needsHumanUnattended()->count();

        return response()->json([
            'count' => $unseenLeads + $unattended,
            'recent' => $recent,
            'escalations' => $escalations,

            'pending_prices' => $pendingPrices,
        ]);
    }

    public function markSeen(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Lead::class);

        $this->markAllSeen($request);

        return response()->json([
            'count' => Conversation::ownedBy($request->user())->when(! $request->user()->can('conversations.view'), fn ($q) => $q->whereRaw('1 = 0'))->needsHumanUnattended()->count(),
        ]);
    }

    public function approvePrice(Request $request, Lead $lead, HaggleDecisionRelay $relay): JsonResponse|RedirectResponse
    {
        $this->authorize('update', $lead);

        $data = $request->validate([

            'price' => ['nullable', 'numeric', 'min:0.01', 'max:99999999'],
        ]);

        abort_unless($lead->awaitsPriceApproval(), 422);

        $relay->approve($lead, isset($data['price']) ? (float) $data['price'] : null);

        return $this->decisionResponse($request, $lead, __('leads.haggle.approved'));
    }

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

    protected function scoped(Request $request)
    {
        return Lead::ownedBy($request->user());
    }

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
