<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\CrmWorkspace;
use App\Models\Deal;
use App\Services\Crm\DealTrend;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The pipeline's own time series — new deals, deals won, and paid revenue —
 * a CRM-specific report distinct from the agent-facing dashboard.
 */
class CrmTrendController extends Controller
{
    public function __construct(protected DealTrend $trend) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Deal::class);

        $window = in_array($request->query('range'), DealTrend::WINDOWS, true) ? $request->query('range') : '8w';
        $workspaces = CrmWorkspace::ownedBy($request->user())->orderByDesc('is_default')->orderBy('name')->get();
        $workspaceId = ctype_digit((string) $request->query('workspace')) && $workspaces->firstWhere('id', (int) $request->query('workspace'))
            ? (int) $request->query('workspace')
            : null;

        return view('crm.trend', [
            'i18nExtraNamespaces' => ['crm'],
            'series' => $this->trend->series($request->user(), $window, $workspaceId),
            'window' => $window,
            'workspaces' => $workspaces,
            'workspaceId' => $workspaceId,
        ]);
    }
}
