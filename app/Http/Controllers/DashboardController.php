<?php

namespace App\Http\Controllers;

use App\Services\Analytics\DashboardMetrics;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The owner panel's front page: what the agents actually did.
 *
 * Deliberately read-only and deliberately one request — every widget is built
 * from the same metrics pass, so switching the chart range (or the agent
 * filter) reloads the page rather than adding a second, differently-shaped
 * source of the same numbers.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $range = (int) $request->query('range', DashboardMetrics::RANGES[0]);
        $agents = $request->user()->agents()->orderBy('name')->get(['id', 'name']);

        // Trusted only if it actually belongs to this owner — an id copied
        // from another tab, or simply guessed, falls back to "all agents"
        // rather than leaking whether that id exists.
        $requestedAgentId = $request->query('agent_id');
        $agentId = $requestedAgentId !== null && $agents->contains('id', (int) $requestedAgentId)
            ? (int) $requestedAgentId
            : null;

        // Constructed here rather than injected: these metrics are scoped to the
        // signed-in owner, and a container-resolved instance would have no user
        // to scope them to.
        $metrics = new DashboardMetrics($request->user());

        // MIGRATED TO INERTIA (phase d). The metrics array is already a plain,
        // JSON-serialisable structure, so it crosses to Vue unchanged — no
        // resource, no transformer, and nothing for the two shapes to drift
        // between. `i18nExtraNamespaces` is gone with the Blade bundle: the
        // Inertia middleware shares every namespace for the active locale.
        return Inertia::render('Dashboard', [
            'metrics' => $metrics->forRange($range, $agentId),
            'agents' => $agents,
        ]);
    }
}
