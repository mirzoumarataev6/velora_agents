<?php

namespace App\Http\Controllers\Bi;

use App\Http\Controllers\Controller;
use App\Http\Requests\Bi\StoreBiDashboardRequest;
use App\Http\Requests\Bi\UpdateBiLayoutRequest;
use App\Models\BiDashboard;
use App\Models\BiDataset;
use App\Models\BiSource;
use App\Services\Bi\Dashboard\AutoAnalysisGenerator;
use App\Services\Bi\Dashboard\LayoutUpdater;
use App\Services\Bi\Dashboard\WidgetRenderer;
use App\Services\Bi\Dashboard\WidgetType;
use App\Services\Bi\Query\BiQueryException;
use App\Services\Bi\Query\SpecValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Boards: list, create, view, rename, reorder, delete.
 *
 * The one surface here that is not ordinary CRUD is `show`, which renders every
 * widget server-side on first paint. That is deliberate and it is the opposite
 * of what a dashboard product usually does.
 *
 * WHY SERVER-RENDERED RATHER THAN A SPINNER PER CARD: the results are cached by
 * `QueryRunner` on a key that already carries the dataset version, so the common
 * case — opening a board you opened this morning — is a handful of cache reads
 * and no queries at all. Rendering those as HTML costs nothing and gives the
 * owner a board that is complete the moment it appears, with no layout shift as
 * eight cards pop in. A board whose cache is cold pays for it once, and the
 * per-card timing is shown rather than hidden.
 *
 * Each card can still refetch itself — see WidgetController::data() — which is
 * what the refresh button and the filter bar use.
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly WidgetRenderer $renderer,
        private readonly LayoutUpdater $layout,
    ) {}

    /**
     * The BI section's landing route redirects here, and this redirects onward
     * to a board — or shows the empty state when there is not one yet.
     */
    public function index(Request $request): View|RedirectResponse
    {
        $this->authorize('viewAny', BiDashboard::class);

        $user = $request->user();
        $dashboards = BiDashboard::ownedBy($user)
            ->with(['source', 'mainDataset'])
            ->withCount('widgets')
            ->orderByDesc('is_default')
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        if ($dashboards->isEmpty()) {
            return view('bi.dashboards.empty', [
                'hasDatasets' => BiDataset::ownedBy($user)->where('status', BiDataset::STATUS_READY)->exists(),
                'sources' => BiSource::ownedBy($user)->with('datasets')->latest('id')->get(),
                'i18nNamespaces' => ['bi', 'agents', 'common'],
            ]);
        }

        return view('bi.dashboards.index', [
            'dashboards' => $dashboards,
            'sources' => BiSource::ownedBy($user)->with('datasets')->latest('id')->get(),
            'i18nNamespaces' => ['bi', 'agents', 'common'],
        ]);
    }

    public function analyze(Request $request, BiSource $source, AutoAnalysisGenerator $generator): RedirectResponse
    {
        $this->authorize('view', $source);
        $this->authorize('update', $source);

        if (BiDashboard::where('team_id', $source->team_id)->count() >= BiDashboard::MAX_PER_OWNER) {
            throw ValidationException::withMessages(['source' => __('bi.errors.too_many_dashboards', ['limit' => BiDashboard::MAX_PER_OWNER])]);
        }

        $selected = null;
        if ($request->filled('dataset_id')) {
            $selected = $source->datasets()->where('team_id', $source->team_id)
                ->where('status', BiDataset::STATUS_READY)
                ->findOrFail($request->integer('dataset_id'));
        }

        if (! $selected && ! $source->datasets()->where('status', BiDataset::STATUS_READY)->exists()) {
            throw ValidationException::withMessages(['source' => __('bi.analysis.not_ready')]);
        }

        $dashboard = $generator->generate($request->user(), $source, $selected);

        return redirect()->route('bi.dashboards.show', $dashboard);
    }

    public function show(Request $request, BiDashboard $dashboard): View|JsonResponse
    {
        $this->authorize('view', $dashboard);

        $widgets = $dashboard->widgets()->with('dataset.columns')->get();

        // Rendering the whole board must enforce the same boundary as the
        // single-widget endpoint, including rows with a corrupt foreign link.
        foreach ($widgets as $widget) {
            $this->authorize('view', $widget);
            abort_unless($widget->team_id === $dashboard->team_id
                && $widget->dataset?->team_id === $dashboard->team_id, 403);
            $this->authorize('query', $widget->dataset);
        }

        // `fresh` is the refresh button: skip the cache for every card on the
        // board at once. Not a query parameter the page links to itself with,
        // so an ordinary navigation always takes the cheap path.
        $fresh = $request->boolean('fresh');

        $rendered = $this->renderer->renderBoard($dashboard, $widgets, $fresh);

        if ($request->expectsJson()) {
            return response()->json(['widgets' => $rendered->map(function ($item) {
                $widget = $item->widget;

                return [
                    'widget' => ['id' => $widget->id, 'title' => $widget->title, 'type' => $widget->type->value,
                        'dataset_id' => $widget->bi_dataset_id, 'spec' => $widget->spec,
                        'filters' => $widget->filters ?? [], 'slot' => $widget->slot()],
                    'html' => view('bi.partials.widget-card', compact('item'))->render(),
                ];
            })->all()]);
        }

        return view('bi.dashboards.show', [
            'dashboard' => $dashboard,
            'rendered' => $rendered,
            // The dataset picker in the widget editor, and the filter bar's
            // column list. Only READY datasets: a widget over one that is still
            // importing would be created already broken.
            'datasets' => BiDataset::where('team_id', $dashboard->team_id)
                ->where('status', BiDataset::STATUS_READY)
                ->with('columns')
                ->orderBy('name')
                ->get(),
            'dashboards' => BiDashboard::where('team_id', $dashboard->team_id)
                ->orderByDesc('is_default')->orderBy('position')->orderBy('id')->get(),
            'widgetTypes' => WidgetType::cases(),
            'statusAsToast' => true,
            'chatMessages' => $dashboard->chatMessages()->where('team_id', $dashboard->team_id)->latest('id')->limit(40)->get()->reverse()->values(),
            'i18nNamespaces' => ['bi', 'agents', 'common'],
        ]);
    }

    public function store(StoreBiDashboardRequest $request): RedirectResponse
    {
        $this->authorize('create', BiDashboard::class);

        $user = $request->user();

        if (BiDashboard::ownedBy($user)->count() >= BiDashboard::MAX_PER_OWNER) {
            throw ValidationException::withMessages([
                'name' => __('bi.errors.too_many_dashboards', ['limit' => BiDashboard::MAX_PER_OWNER]),
            ]);
        }

        $dashboard = new BiDashboard($request->safe()->except('is_default'));
        $dashboard->user_id = $user->id;
        $dashboard->team_id = $user->activeTeamId();
        $dashboard->position = (int) BiDashboard::ownedBy($user)->max('position') + 1;
        $dashboard->save();

        // The first board an account creates becomes its landing board without
        // being asked — there is nothing else for the section to open.
        if ($request->boolean('is_default') || BiDashboard::ownedBy($user)->count() === 1) {
            $dashboard->makeDefault();
        }

        return redirect()
            ->route('bi.dashboards.show', $dashboard)
            ->with('status', __('bi.dashboards.created'));
    }

    public function update(StoreBiDashboardRequest $request, BiDashboard $dashboard, SpecValidator $validator): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $dashboard);

        $filters = $request->validated('filters', []);
        foreach ($filters as $datasetId => $entries) {
            $dataset = BiDataset::where('team_id', $dashboard->team_id)->whereKey($datasetId)->first();
            if (! $dataset) {
                throw ValidationException::withMessages(['filters' => __('bi.validation.dataset_unknown')]);
            }
            try {
                $validator->validate($dataset, ['measures' => [['aggregate' => 'count']], 'filters' => $entries]);
            } catch (BiQueryException $e) {
                throw ValidationException::withMessages(['filters' => __($e->translationKey())]);
            }
        }

        $dashboard->fill($request->safe()->except('is_default'))->save();
        $dashboard->filters = $filters;
        $dashboard->save();

        if ($request->boolean('is_default')) {
            $dashboard->makeDefault();
        }

        if ($request->expectsJson()) {
            return response()->json(['filters' => $dashboard->filters, 'message' => __('bi.dashboards.saved')]);
        }

        return back()->with('status', __('bi.dashboards.saved'));
    }

    /**
     * Persist a drag.
     *
     * Its own endpoint rather than part of `update`, because it is a different
     * operation with a different shape: no form fields, many rows, and no
     * `updated_at` bump. See LayoutUpdater.
     */
    public function updateLayout(UpdateBiLayoutRequest $request, BiDashboard $dashboard): JsonResponse
    {
        $this->authorize('update', $dashboard);

        $moved = $this->layout->apply($dashboard, $request->validated('layout'));

        return response()->json(['moved' => $moved]);
    }

    public function destroy(Request $request, BiDashboard $dashboard): RedirectResponse
    {
        $this->authorize('delete', $dashboard);

        $wasDefault = $dashboard->is_default;
        $user = $request->user();

        // Widgets go with it through the foreign key's cascade. Nothing else
        // needs cleaning up: a dashboard owns no files and no physical tables,
        // which is why — unlike BiDataset — it has no observer.
        $dashboard->delete();

        // Promote another board so the section still has somewhere to land.
        if ($wasDefault) {
            BiDashboard::landingFor($user)?->makeDefault();
        }

        return redirect()
            ->route('bi.dashboards.index')
            ->with('status', __('bi.dashboards.deleted'));
    }
}
