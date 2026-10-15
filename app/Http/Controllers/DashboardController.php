<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Services\Analytics\DashboardMetrics;
use App\Services\Permissions\PermissionCatalog;
use Illuminate\Http\Request;
use Illuminate\View\View;

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
    public function __invoke(Request $request): View
    {
        $this->authorize('analytics.view');

        $range = (int) $request->query('range', DashboardMetrics::RANGES[0]);
        $agents = Agent::ownedBy($request->user())->orderBy('name')->get(['id', 'name']);

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
        //
        // H4: /dashboard itself stays gated by analytics.view alone (not
        // widened to require crm.view too) — the permission check that
        // matters for lead figures happens inside DashboardMetrics, which
        // is handed the answer here rather than calling Gate itself.
        $metrics = new DashboardMetrics($request->user(), $request->user()->can(PermissionCatalog::CRM_VIEW));

        return view('dashboard.index', [
            // $agents is handed in rather than re-fetched inside
            // agentComparison(): it is the exact same ownedBy() query,
            // columns and order, so a second copy was a pure duplicate.
            'metrics' => $metrics->forRange($range, $agentId, $agents),
            'agents' => $agents,
            'i18nExtraNamespaces' => ['dashboard'],
        ]);
    }
}
