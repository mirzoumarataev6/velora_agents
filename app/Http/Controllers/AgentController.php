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
    public const TABS = ['knowledge', 'catalog', 'database', 'channels', 'playground', 'conversations', 'settings'];

    public function index(Request $request, OnboardingService $onboarding): View
    {
        $this->authorize('viewAny', Agent::class);

        $user = $request->user();

        $agents = Agent::ownedBy($user)
            ->withCount(['knowledgeSources', 'channels', 'conversations'])
            ->latest()
            ->get();

        $checklist = $onboarding->forUser(
            $user,
            $request->session()->get(AuthController::ONBOARDING_SESSION_KEY, false) === true,
        );

        if ($checklist['celebrate']) {
            $user->markOnboardingCompleted();
        }

        return view('agents.index', compact('agents') + [
            'onboarding' => $checklist,
            'i18nExtraNamespaces' => ['agents'],
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Agent::class);

        return view('agents.form', ['agent' => new Agent, 'i18nExtraNamespaces' => ['agents']]);
    }

    public function store(StoreAgentRequest $request): RedirectResponse
    {
        $agent = new Agent($request->agentAttributes());
        $agent->user_id = $request->user()->id;
        $agent->team_id = $request->user()->activeTeamId();
        $agent->save();

        return redirect()->route('agents.show', $agent)->with('status', __('agents.controllers.agent_created'));
    }

    public function show(Request $request, Agent $agent): View
    {
        $this->authorize('view', $agent);

        $tabs = collect([
            'knowledge' => 'agents.view', 'catalog' => 'catalog.view',
            'database' => 'catalog.view', 'channels' => 'channels.view',
            'playground' => 'agents.update', 'conversations' => 'conversations.view',
            'settings' => 'agents.update',
        ])->filter(fn ($permission) => $request->user()->can($permission, $agent))->keys()->all();

        $agent->load(['knowledgeSources' => fn ($q) => $q->withCount('chunks')->latest()]);
        if (in_array('channels', $tabs, true) || in_array('conversations', $tabs, true)) {
            $agent->load('channels');
        } else {
            $agent->setRelation('channels', collect());
        }

        $playgroundConversation = in_array('playground', $tabs, true)
            ? $agent->conversations()
                ->with(['messages' => fn ($q) => $q->orderBy('created_at')->orderBy('id')])
                ->whereNull('channel_id')->where('external_user_id', 'playground')->first()
            : null;
        $tab = in_array($request->query('tab'), $tabs, true) ? $request->query('tab') : $tabs[0];

        return view('agents.show', compact('agent', 'tab', 'tabs', 'playgroundConversation') + [
            'i18nExtraNamespaces' => ['agents', 'conversations'],

            'conversationFilters' => ConversationController::defaultFilters($agent->id),
            'conversationChannelTypes' => $agent->channels->pluck('type')->unique()->values(),

            'catalog' => in_array('catalog', $tabs, true) ? DatasetController::state($agent) : [],

            'database' => in_array('database', $tabs, true) ? DatabaseConnectionController::state($agent) : [],
        ]);
    }

    public function edit(Agent $agent): View
    {
        $this->authorize('update', $agent);

        return view('agents.form', compact('agent') + ['i18nExtraNamespaces' => ['agents']]);
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
