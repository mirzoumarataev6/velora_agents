<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Services\Analytics\DashboardMetrics;
use App\Services\Permissions\PermissionCatalog;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $this->authorize('analytics.view');

        $range = (int) $request->query('range', DashboardMetrics::RANGES[0]);
        $agents = Agent::ownedBy($request->user())->orderBy('name')->get(['id', 'name']);

        $requestedAgentId = $request->query('agent_id');
        $agentId = $requestedAgentId !== null && $agents->contains('id', (int) $requestedAgentId)
            ? (int) $requestedAgentId
            : null;

        $metrics = new DashboardMetrics($request->user(), $request->user()->can(PermissionCatalog::CRM_VIEW));

        return view('dashboard.index', [

            'metrics' => $metrics->forRange($range, $agentId, $agents),
            'agents' => $agents,
            'i18nExtraNamespaces' => ['dashboard'],
        ]);
    }
}
