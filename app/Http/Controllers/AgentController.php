<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAgentRequest;
use App\Models\Agent;
use App\Services\OnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response;

class AgentController extends Controller
{
    /**
     * The tabs on the agent detail page, in nav order.
     *
     * `?tab=` is what carries the open tab across a reload and a language
     * switch, so an arbitrary value would otherwise reach the page — and every
     * panel is `x-show`n against it, so an unrecognized one would render a page
     * with no content at all. Anything not on this list falls back to the first.
     */
    public const TABS = ['knowledge', 'catalog', 'database', 'channels', 'playground', 'conversations', 'settings'];

    public function index(Request $request, OnboardingService $onboarding): Response
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

        return Inertia::render('Agents/Index', [
            'agents' => $agents->map(fn (Agent $agent) => [
                'id' => $agent->id,
                'slug' => $agent->slug,
                'name' => $agent->name,
                'description' => $agent->description,
                'status' => $agent->status,
                'knowledge_sources_count' => $agent->knowledge_sources_count,
                'channels_count' => $agent->channels_count,
                'conversations_count' => $agent->conversations_count,
            ])->all(),
            'onboarding' => $checklist,
            // The sign-up greeting, claimed HERE when there is a checklist to
            // pin it above — which is what stops the shared layout from also
            // toasting it. Claiming it spends the session key and stamps the
            // account welcomed, so this page's banner replaces the welcome
            // modal rather than appearing beside it. One greeting per arrival.
            'banner' => $this->claimWelcomeBanner($request, $checklist['visible']),
        ]);
    }

    /**
     * The owner's name, if this page owes them a sign-up banner.
     */
    private function claimWelcomeBanner(Request $request, bool $checklistVisible): ?string
    {
        if (! $checklistVisible) {
            return null;
        }

        if ($request->session()->get(AuthController::GREETING_SESSION_KEY) !== AuthController::GREETING_WELCOME) {
            return null;
        }

        $request->session()->forget(AuthController::GREETING_SESSION_KEY);
        $request->user()->markWelcomed();

        return $request->user()->name;
    }

    public function create(): Response
    {
        $this->authorize('create', Agent::class);

        return Inertia::render('Agents/Form', ['agent' => null]);
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

        $tab = in_array($request->query('tab'), self::TABS, true)
            ? $request->query('tab')
            : self::TABS[0];

        return view('agents.show', compact('agent', 'tab', 'playgroundConversation') + [
            'i18nExtraNamespaces' => ['conversations'],
            // The conversations tab renders the shared inbox partial, pinned to
            // this agent. Channel types come off the already-loaded relation so
            // the tab costs no extra query on a page that never opens it.
            'conversationFilters' => ConversationController::defaultFilters($agent->id),
            'conversationChannelTypes' => $agent->channels->pluck('type')->unique()->values(),
            // Seeds the catalog tab so it paints its real state on first render
            // rather than flashing "empty" until an XHR comes back.
            'catalog' => DatasetController::state($agent),
            // Same idea for the live-connection tab: it paints its real state on
            // first render rather than flashing "not connected".
            'database' => DatabaseConnectionController::state($agent),
        ]);
    }

    public function edit(Agent $agent): Response
    {
        $this->authorize('update', $agent);

        return Inertia::render('Agents/Form', ['agent' => $this->formAttributes($agent)]);
    }

    /**
     * The agent, flattened into exactly the shape the form posts back.
     *
     * `response_format` is a JSON column on the model but four separate fields
     * on the form, and StoreAgentRequest reassembles it. Unpacking it here —
     * rather than letting the form reach into a nested object — keeps the two
     * halves of that contract next to each other.
     *
     * @return array<string, mixed>
     */
    private function formAttributes(Agent $agent): array
    {
        $format = $agent->response_format ?? [];
        $behavior = $agent->behavior ?? [];

        return [
            'slug' => $agent->slug,
            'name' => $agent->name,
            'description' => $agent->description,
            'tone' => $agent->tone,
            'greeting_message' => $format['greeting_message'] ?? null,
            'fallback_message' => $format['fallback_message'] ?? null,
            'max_length' => $format['max_length'] ?? null,
            'use_emojis' => (bool) ($format['use_emojis'] ?? false),
            'custom_system_prompt' => $agent->custom_system_prompt,
            'rules_instructions' => $agent->rules_instructions,
            // `behavior` is the other JSON column the request reassembles.
            'on_not_found' => $behavior['on_not_found'] ?? 'apologize',
            'escalation' => $behavior['escalation'] ?? config('agent.escalation.default_sensitivity', 'conservative'),
            'proactivity' => $agent->proactivity,
            'haggling_enabled' => (bool) $agent->haggling_enabled,
            'haggling_level' => $agent->haggling_level,
            'haggle_min_price_percent' => $agent->haggle_min_price_percent,
            'haggling_instructions' => $agent->haggling_instructions,
        ];
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
