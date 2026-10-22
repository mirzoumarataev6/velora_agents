<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\KnowledgeSource;
use App\Models\User;

class OnboardingService
{
    public function forUser(User $user, bool $fromRegistration = false): array
    {

        $agents = $user->agents()->orderBy('id')->get(['id', 'slug']);
        $agentIds = $agents->pluck('id');
        $first = $agents->first();

        $hasAgent = $first !== null;
        $hasKnowledge = $hasAgent && KnowledgeSource::whereIn('agent_id', $agentIds)->exists();
        $hasChannel = $hasAgent && Channel::whereIn('agent_id', $agentIds)->exists();

        $tab = fn (string $tab) => $hasAgent
            ? route('agents.show', ['agent' => $first->slug, 'tab' => $tab])
            : route('agents.create');

        $steps = [
            ['key' => 'agent', 'done' => $hasAgent, 'cta' => route('agents.create')],
            ['key' => 'knowledge', 'done' => $hasKnowledge, 'cta' => $tab('knowledge')],
            ['key' => 'channel', 'done' => $hasChannel, 'cta' => $tab('channels')],
            ['key' => 'playground', 'done' => $user->playground_tried_at !== null, 'cta' => $tab('playground')],
        ];

        $total = count($steps);
        $completed = count(array_filter($steps, fn (array $step) => $step['done']));
        $complete = $completed === $total;

        $allowed = $this->isAllowedFor($user, $fromRegistration);

        $celebrate = $allowed && $complete && $user->onboarding_completed_at === null;

        return [
            'steps' => $steps,
            'completed' => $completed,
            'total' => $total,
            'percent' => (int) round($completed / $total * 100),
            'complete' => $complete,

            'visible' => $allowed && ($complete ? $celebrate : true),
            'celebrate' => $celebrate,
        ];
    }

    protected function isAllowedFor(User $user, bool $fromRegistration): bool
    {

        if (! $fromRegistration) {
            return false;
        }

        return $user->onboarding_dismissed_at === null;
    }
}
