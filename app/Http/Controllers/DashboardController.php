<?php

namespace App\Http\Controllers;

use App\Services\Analytics\DashboardMetrics;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The owner panel's front page: what the agents actually did.
 *
 * Deliberately read-only and deliberately one request — every widget is built
 * from the same metrics pass, so switching the chart range reloads the page
 * rather than adding a second, differently-shaped source of the same numbers.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $range = (int) $request->query('range', DashboardMetrics::RANGES[0]);

        // Constructed here rather than injected: these metrics are scoped to the
        // signed-in owner, and a container-resolved instance would have no user
        // to scope them to.
        $metrics = new DashboardMetrics($request->user());

        return view('dashboard.index', [
            'metrics' => $metrics->forRange($range),
            'i18nExtraNamespaces' => ['dashboard'],
        ]);
    }
}
