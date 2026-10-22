<?php

namespace App\Http\Controllers;

use App\Exceptions\LlmException;
use App\Models\Agent;
use App\Services\Agents\AgentPersonaAssistant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class PersonaAssistantController extends Controller
{
    public function __invoke(Request $request, AgentPersonaAssistant $assistant): JsonResponse
    {
        $this->authorize('create', Agent::class);

        $data = $request->validate([

            'agent' => ['nullable', 'string', 'max:255'],

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

    protected function contextAgent(Request $request, array $data): Agent
    {
        $agent = null;

        if (! empty($data['agent'])) {
            $agent = Agent::query()
                ->where('slug', $data['agent'])
                ->orWhere('id', is_numeric($data['agent']) ? (int) $data['agent'] : 0)
                ->first();

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
