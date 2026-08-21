<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\KnowledgeSource;
use App\Models\User;

/**
 * The first-run setup checklist.
 *
 * Step state is DERIVED from the account's real data on every render rather
 * than tracked in its own columns. That is the whole design: an owner who
 * created an agent before the checklist was ever shown to them sees that step
 * already ticked, and no code path can leave the checklist claiming something
 * different from what the dashboard behind it shows. The only things stored
 * are the two facts the rest of the schema genuinely does not hold — whether
 * the sandbox has been tried, and whether the owner asked to be left alone.
 */
class OnboardingService
{
    /**
     * Build the checklist for an owner.
     *
     * Costs one query for an account with no agents, three for any other —
     * cheap enough to run on every dashboard landing without a cache to
     * invalidate.
     *
     * $fromRegistration is the gate: the checklist belongs to the sign-up, so
     * only a session that began with one may see it. The caller supplies it
     * (AgentController reads AuthController::ONBOARDING_SESSION_KEY) rather
     * than this service reaching for the session itself, which keeps it a pure
     * function of its inputs and testable without a request.
     *
     * @return array{steps: list<array{key: string, done: bool, cta: ?string}>, completed: int, total: int, percent: int, complete: bool, visible: bool, celebrate: bool}
     */
    public function forUser(User $user, bool $fromRegistration = false): array
    {
        // id and slug together: the ids drive the two exists() probes below,
        // the first slug builds the deep links. Owners have a handful of
        // agents, so this is smaller than the counts it replaces.
        $agents = $user->agents()->orderBy('id')->get(['id', 'slug']);
        $agentIds = $agents->pluck('id');
        $first = $agents->first();

        $hasAgent = $first !== null;
        $hasKnowledge = $hasAgent && KnowledgeSource::whereIn('agent_id', $agentIds)->exists();
        $hasChannel = $hasAgent && Channel::whereIn('agent_id', $agentIds)->exists();

        // Every CTA past the first needs somewhere to point, and until an agent
        // exists there is nowhere — so those steps fall back to the create
        // screen, which is the real prerequisite anyway.
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

        // Finishing earns one congratulations panel, not a permanent one: the
        // stamp is written the first time the finished state renders (see
        // AgentController@index) and hides the card from then on.
        //
        // Gated on $allowed too, so a session that may not show the checklist
        // cannot silently spend the panel on a card nobody ever saw.
        $celebrate = $allowed && $complete && $user->onboarding_completed_at === null;

        return [
            'steps' => $steps,
            'completed' => $completed,
            'total' => $total,
            'percent' => (int) round($completed / $total * 100),
            'complete' => $complete,
            // Once every step is done the card has only one thing left to say,
            // and it says it once: after that a finished checklist is not shown
            // again.
            'visible' => $allowed && ($complete ? $celebrate : true),
            'celebrate' => $celebrate,
        ];
    }

    /**
     * The two gates that outrank progress entirely.
     */
    protected function isAllowedFor(User $user, bool $fromRegistration): bool
    {
        // Onboarding is for newly registered users only. A session that did not
        // begin with a sign-up never sees it, no matter how much or how little
        // setup the account has done.
        if (! $fromRegistration) {
            return false;
        }

        // Skipping is permanent — an owner who said "not now" should not have
        // the panel reappear because they later happened to finish a step.
        return $user->onboarding_dismissed_at === null;
    }
}
