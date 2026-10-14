<?php

namespace App\Http\Controllers\Bi;

use App\Http\Controllers\Controller;
use App\Http\Requests\Bi\StoreBiWidgetRequest;
use App\Models\BiDashboard;
use App\Models\BiDataset;
use App\Models\BiWidget;
use App\Services\Bi\Dashboard\WidgetRenderer;
use App\Services\Bi\Dashboard\WidgetSpecBuilder;
use App\Services\Bi\Dashboard\WidgetType;
use App\Services\Bi\Query\BiQueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Cards on a board: add, edit, refetch, delete.
 *
 * THE TWO-AUTHORIZATION RULE runs through every method here. A widget request
 * names two things the caller must own — the board it is on and the dataset it
 * reads — and both are checked, separately, every time. See BiWidgetPolicy for
 * why collapsing them would be a hole: a widget row whose `bi_dataset_id` had
 * been tampered with would otherwise be rendered having proven only that the
 * CARD belongs to the caller.
 *
 * Routes are nested under the dashboard and use `->scopeBindings()`, so a
 * widget id from another board 404s at the router rather than reaching a policy
 * that would have said yes.
 */
class WidgetController extends Controller
{
    public function __construct(private readonly WidgetRenderer $renderer, private readonly WidgetSpecBuilder $specs) {}

    public function store(StoreBiWidgetRequest $request, BiDashboard $dashboard): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $dashboard);

        if ($dashboard->isFull()) {
            throw ValidationException::withMessages([
                'title' => __('bi.errors.too_many_widgets', ['limit' => BiDashboard::MAX_WIDGETS]),
            ]);
        }

        $dataset = $this->dataset($request, $dashboard);
        $type = WidgetType::from($request->validated('type'));

        $widget = new BiWidget($request->safe()->only([
            'title', 'type', 'spec', 'filters', 'options',
            'grid_x', 'grid_y', 'grid_w', 'grid_h',
        ]));

        $widget->user_id = $request->user()->id;
        $widget->team_id = $dashboard->team_id;
        $widget->bi_dashboard_id = $dashboard->getKey();
        $widget->bi_dataset_id = $dataset->getKey();
        $widget->filters = $request->validated('filters', []);

        // Only where the request did not place it. A card added by dragging
        // from the palette knows where it was dropped; one added from the
        // editor does not, and goes to the bottom.
        $widget->forceFill(array_filter(
            BiWidget::defaultSlot($dashboard, $type),
            fn ($_, $key) => $widget->{$key} === null,
            ARRAY_FILTER_USE_BOTH
        ));

        $this->validateWidget($widget, $dataset);

        $widget->save();

        if ($request->expectsJson()) {
            return $this->present($widget, $dashboard, __('bi.widgets.created'));
        }

        return back()->with('status', __('bi.widgets.created'));
    }

    public function update(StoreBiWidgetRequest $request, BiDashboard $dashboard, BiWidget $widget): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $dashboard);
        $this->authorize('update', $widget);

        $dataset = $this->dataset($request, $dashboard);

        $widget->fill($request->safe()->only([
            'title', 'type', 'spec', 'filters', 'options',
            'grid_x', 'grid_y', 'grid_w', 'grid_h',
        ]));

        // Repointing a card at a different dataset is allowed, and is the
        // reason the dataset is authorized on update as well as on create.
        $widget->bi_dataset_id = $dataset->getKey();
        $this->validateWidget($widget, $dataset);
        $widget->save();

        if ($request->expectsJson()) {
            return $this->present($widget, $dashboard, __('bi.widgets.saved'));
        }

        return back()->with('status', __('bi.widgets.saved'));
    }

    /**
     * One card's numbers, as JSON — the refresh button and the filter bar.
     *
     * Returns 200 with an `error` key rather than an HTTP error status when the
     * query is refused. The card has already rendered; what the browser needs is
     * the sentence to put inside it, and a 422 here would be indistinguishable
     * from the request itself being malformed.
     */
    public function data(Request $request, BiDashboard $dashboard, BiWidget $widget): JsonResponse
    {
        $this->authorize('view', $dashboard);
        $this->authorize('view', $widget);
        abort_unless($widget->team_id === $dashboard->team_id
            && $widget->dataset?->team_id === $dashboard->team_id, 403);
        $this->authorize('query', $widget->dataset);

        $request->validate([
            'offset' => ['sometimes', 'integer', 'min:0'],
            'sort' => ['sometimes', 'regex:/^[dm][0-9]+$/'],
            'direction' => ['sometimes', 'in:asc,desc'],
            'search_column' => ['sometimes', 'regex:/^c[0-9]+$/'],
            'search' => ['sometimes', 'string', 'max:120'],
        ]);

        // A table page is an ephemeral variation of the saved QuerySpec. The
        // request may choose a validated alias or text column, never SQL.
        if ($widget->type === WidgetType::Table && ($request->has('offset') || $request->has('sort') || $request->filled('search'))) {
            $copy = clone $widget;
            $spec = $widget->spec;
            $spec['offset'] = $request->integer('offset');
            if ($request->filled('sort')) {
                $spec['sorts'] = [['field' => $request->query('sort'), 'direction' => $request->query('direction', 'asc')]];
            }
            $copy->spec = $spec;
            if ($request->filled('search')) {
                $column = $widget->dataset->columns()->where('physical_name', $request->query('search_column'))->first();
                if (! $column || $column->type->value !== 'text') {
                    throw ValidationException::withMessages(['search_column' => __('bi.errors.unknown_column')]);
                }
                $copy->filters = [...$widget->ownFilters(), ['column' => $column->physical_name, 'operator' => 'contains', 'value' => $request->query('search')]];
            }
            $widget = $copy;
        }

        $rendered = $this->renderer->render($widget, $dashboard, $request->boolean('fresh'));

        if ($rendered->failed()) {
            return response()->json([
                'error' => $rendered->errorCode,
                'message' => __($rendered->errorKey()),
                'transient' => $rendered->transient,
            ]);
        }

        return response()->json([
            'rows' => $rendered->result->rows,
            'columns' => $rendered->result->columns,
            'has_more' => $rendered->result->hasMore,
            'cached' => $rendered->result->cached,
            'duration_ms' => $rendered->result->durationMs,
            'offset' => $rendered->result->offset,
            'page_size' => $rendered->result->pageSize,
        ]);
    }

    public function destroy(Request $request, BiDashboard $dashboard, BiWidget $widget): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $dashboard);
        $this->authorize('delete', $widget);

        $widget->delete();

        if ($request->expectsJson()) {
            return response()->json(['id' => $widget->id, 'message' => __('bi.widgets.deleted')]);
        }

        return back()->with('status', __('bi.widgets.deleted'));
    }

    private function validateWidget(BiWidget $widget, BiDataset $dataset): void
    {
        $dataset->load('columns');
        $widget->setRelation('dataset', $dataset);
        try {
            $this->specs->build($widget);
        } catch (BiQueryException $e) {
            throw ValidationException::withMessages(['spec' => __($e->translationKey())]);
        }
    }

    private function present(BiWidget $widget, BiDashboard $dashboard, string $message): JsonResponse
    {
        $widget->loadMissing('dataset.columns');
        $item = $this->renderer->render($widget, $dashboard);

        return response()->json([
            'widget' => [
                'id' => $widget->id, 'title' => $widget->title, 'type' => $widget->type->value,
                'dataset_id' => $widget->bi_dataset_id, 'spec' => $widget->spec,
                'filters' => $widget->filters ?? [], 'slot' => $widget->slot(),
            ],
            'html' => view('bi.partials.widget-card', compact('item'))->render(),
            'message' => $message,
        ]);
    }

    /**
     * The dataset a request names, authorized and proven ready.
     *
     * Looked up through `ownedBy` AND then run through the policy. The scope is
     * what makes a foreign id a 404-shaped validation error instead of a 403
     * that confirms the row exists; the policy is the check that would still be
     * there if someone replaced the scope with a plain `find`.
     */
    private function dataset(StoreBiWidgetRequest $request, BiDashboard $dashboard): BiDataset
    {
        $dataset = BiDataset::where('team_id', $dashboard->team_id)
            ->whereKey($request->validated('bi_dataset_id'))
            ->first();

        if ($dataset === null) {
            throw ValidationException::withMessages([
                'bi_dataset_id' => __('bi.validation.dataset_unknown'),
            ]);
        }

        $this->authorize('query', $dataset);

        return $dataset;
    }
}
