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

        $widget->bi_dataset_id = $dataset->getKey();
        $this->validateWidget($widget, $dataset);
        $widget->save();

        if ($request->expectsJson()) {
            return $this->present($widget, $dashboard, __('bi.widgets.saved'));
        }

        return back()->with('status', __('bi.widgets.saved'));
    }

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
