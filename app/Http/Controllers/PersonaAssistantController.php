<?php

namespace App\Http\Controllers;

use App\Exceptions\LlmException;
use App\Models\Agent;
use App\Services\Agents\AgentPersonaAssistant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Drafts the rules and persona blocks for the agent form.
 *
 * Not bound to an agent in the route, because the most useful moment to reach
 * for this is while CREATING one — when there is nothing to bind to. The agent
 * is optional and arrives in the body: given, it supplies catalog and knowledge
 * context; absent, the name and description typed into the form so far are all
 * the context there is, which is still enough to be worth something.
 *
 * Returns a draft and saves nothing. Persisting is the form's job, after the
 * owner has read it.
 */
class PersonaAssistantController extends Controller
{
    public function __invoke(Request $request, AgentPersonaAssistant $assistant): JsonResponse
    {
        $this->authorize('create', Agent::class);

        $data = $request->validate([
            // The agent being edited, if any. Its own policy check is below —
            // an id in a request body is a claim, not an authorisation.
            'agent' => ['nullable', 'string', 'max:255'],
            // Taken from the live form rather than from the database: an owner
            // who has just retyped the description expects the draft to reflect
            // what is on screen, not what was saved last week.
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'answers' => ['required', 'array'],
            'answers.*' => ['nullable', 'string', 'max:'.AgentPersonaAssistant::MAX_ANSWER_LENGTH],
        ]);

        $agent = $this->contextAgent($request, $data);

        $answers = array_intersect_key(
            $data['answers'],
            array_flip(AgentPersonaAssistant::QUESTIONS),
        );

        if (collect($answers)->filter(fn ($answer) => trim((string) $answer) !== '')->isEmpty()) {
            return response()->json([
                'message' => __('agents.persona_assistant.errors.no_answers'),
            ], 422);
        }

        try {
            $draft = $assistant->draft($agent, $answers);
        } catch (LlmException $e) {
            Log::warning('Persona draft generation failed', [
                'agent_id' => $agent->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => __('agents.persona_assistant.errors.failed')], 502);
        } catch (Throwable $e) {
            Log::error('Persona draft generation crashed', [
                'agent_id' => $agent->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => __('agents.persona_assistant.errors.failed')], 502);
        }

        return response()->json([
            'rules' => $draft['rules'],
            'persona' => $draft['persona'],
        ]);
    }

    /**
     * The agent the draft is written for.
     *
     * An existing one when the owner is editing (so the catalog and knowledge
     * base can inform the draft), otherwise an unsaved stand-in carrying just
     * what the form has. Either way the name and description come from the
     * form — the point of the helper is to work on what is on screen.
     *
     * @param  array<string, mixed>  $data
     */
    protected function contextAgent(Request $request, array $data): Agent
    {
        $agent = null;

        if (! empty($data['agent'])) {
            $agent = Agent::query()
                ->where('slug', $data['agent'])
                ->orWhere('id', is_numeric($data['agent']) ? (int) $data['agent'] : 0)
                ->first();

            // Silently falling back to a blank agent rather than 403-ing: the
            // draft is written from the form's own fields either way, and the
            // only thing an unowned id could leak is catalog context — which is
            // exactly what this drops.
            if ($agent !== null && ($request->user()->cannot('update', $agent) || $request->user()->cannot('catalog.view', $agent))) {
                $agent = null;
            }
        }

        $agent ??= new Agent;

        $agent->name = $data['name'];
        $agent->description = $data['description'] ?? null;

        return $agent;
    }
}
