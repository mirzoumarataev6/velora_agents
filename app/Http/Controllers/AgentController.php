<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAgentRequest;
use App\Models\Agent;
use App\Services\OnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AgentController extends Controller
{
    public function index(Request $request, OnboardingService $onboarding): View
    {
        $user = $request->user();

        $agents = $user->agents()
            ->withCount(['knowledgeSources', 'channels', 'conversations'])
            ->latest()
            ->get();

        $checklist = $onboarding->forUser(
            $user,
            $request->session()->get(AuthController::ONBOARDING_SESSION_KEY, false) === true,
        );

        // Stamped here rather than inside the service so the write is visible
        // at the call site: rendering the finished checklist is what spends the
        // congratulations panel, and it is spent exactly once.
        if ($checklist['celebrate']) {
            $user->markOnboardingCompleted();
        }

        return view('agents.index', compact('agents') + ['onboarding' => $checklist]);
    }

    public function create(): View
    {
        $this->authorize('create', Agent::class);

        return view('agents.form', ['agent' => new Agent]);
    }

    public function store(StoreAgentRequest $request): RedirectResponse
    {
        $agent = $request->user()->agents()->create($request->agentAttributes());

        return redirect()->route('agents.show', $agent)->with('status', __('agents.controllers.agent_created'));
    }

    public function show(Request $request, Agent $agent): View
    {
        $this->authorize('view', $agent);

        $agent->load(['knowledgeSources' => fn ($q) => $q->withCount('chunks')->latest(), 'channels']);

        $playgroundConversation = $agent->conversations()
            ->with(['messages' => fn ($q) => $q->orderBy('created_at')->orderBy('id')])
            ->where('channel_id', null)
            ->where('external_user_id', 'playground')
            ->first();

        $tab = $request->query('tab', 'knowledge');

        return view('agents.show', compact('agent', 'tab', 'playgroundConversation') + [
            'i18nExtraNamespaces' => ['conversations'],
            // The conversations tab renders the shared inbox partial, pinned to
            // this agent. Channel types come off the already-loaded relation so
            // the tab costs no extra query on a page that never opens it.
            'conversationFilters' => ConversationController::defaultFilters($agent->id),
            'conversationChannelTypes' => $agent->channels->pluck('type')->unique()->values(),
        ]);
    }

    public function edit(Agent $agent): View
    {
        $this->authorize('update', $agent);

        return view('agents.form', compact('agent'));
    }

    public function update(StoreAgentRequest $request, Agent $agent): RedirectResponse
    {
        $agent->update($request->agentAttributes());

        return redirect()->route('agents.show', ['agent' => $agent, 'tab' => 'settings'])
            ->with('status', __('agents.controllers.agent_updated'));
    }

    public function destroy(Agent $agent): RedirectResponse
    {
        $this->authorize('delete', $agent);

        $agent->delete();

        return redirect()->route('agents.index')->with('status', __('agents.controllers.agent_deleted'));
    }

    public function updateStatus(Request $request, Agent $agent): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $agent);

        $request->validate(['status' => ['required', 'in:active,paused']]);

        $agent->update(['status' => $request->string('status')]);
        $message = $agent->status === 'active' ? __('agents.controllers.agent_activated') : __('agents.controllers.agent_paused');

        if ($request->wantsJson()) {
            return response()->json(['status' => $agent->status, 'message' => $message]);
        }

        return redirect()->route('agents.show', ['agent' => $agent, 'tab' => 'settings'])
            ->with('status', $message);
    }
}
