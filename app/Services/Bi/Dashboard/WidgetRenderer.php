<?php

namespace App\Services\Bi\Dashboard;

use App\Models\BiDashboard;
use App\Models\BiWidget;
use App\Services\Bi\Query\BiQueryException;
use App\Services\Bi\Query\QueryRunner;
use App\Services\Bi\Query\SpecValidator;
use App\Services\Bi\Schema\ColumnRole;
use Illuminate\Support\Collection;

/**
 * Runs the widgets of a board and hands back what each one came up with.
 *
 * CACHING IS NOT DONE HERE, and that is the point worth recording. Every widget
 * goes through `QueryRunner`, which already caches on a key built from the
 * dataset version and the spec's fingerprint. A second cache at this level
 * would be keyed by widget id — and a widget's id does not change when its
 * filters do, so it would serve the previous question's numbers under the new
 * filter. The existing key is strictly better: two widgets asking the identical
 * question of the same dataset share one entry even across different boards,
 * and changing a filter is automatically a different key.
 *
 * What this class adds on top is per-card failure isolation. See RenderedWidget.
 */
class WidgetRenderer
{
    public function __construct(
        protected readonly WidgetSpecBuilder $specs = new WidgetSpecBuilder,
        protected readonly QueryRunner $runner = new QueryRunner,
        protected readonly FilterMerger $filters = new FilterMerger,
        protected readonly SpecValidator $validator = new SpecValidator,
    ) {}

    /**
     * Render one card.
     *
     * Never throws for a data reason. The only exceptions that escape are the
     * ones that are not about this widget — a bug in our own code — and those
     * should reach the error handler rather than be drawn as a tidy message.
     */
    public function render(BiWidget $widget, ?BiDashboard $dashboard = null, bool $fresh = false): RenderedWidget
    {
        try {
            $spec = $this->specs->build($widget, $dashboard);
            $result = $this->runner->run($spec, $fresh);

            return RenderedWidget::ok(
                $widget,
                $result,
                $this->filters->overriddenColumns($dashboard, $widget),
                $widget->type === WidgetType::Kpi ? $this->trend($widget, $dashboard) : null,
            );
        } catch (BiQueryException $e) {
            return RenderedWidget::fromError($widget, $e);
        }
    }

    /** Compare the last populated calendar month with its predecessor. */
    private function trend(BiWidget $widget, ?BiDashboard $dashboard): ?float
    {
        $dataset = $widget->dataset;
        $time = $dataset?->columns->first(fn ($column) => $column->role === ColumnRole::Time && $column->type->isTemporal());
        if (! $time) {
            return null;
        }

        try {
            $measure = $widget->spec['measures'][0] ?? null;
            if (! $measure) {
                return null;
            }
            $latest = $this->runner->run($this->validator->validate($dataset, [
                'measures' => [['aggregate' => 'max', 'column' => $time->physical_name]], 'limit' => 1,
            ]))->rows[0]['m0'] ?? null;
            if (! $latest) {
                return null;
            }
            $end = new \DateTimeImmutable((string) $latest);
            $current = $end->modify('first day of this month')->setTime(0, 0);
            $previous = $current->modify('-1 month');
            $value = function (\DateTimeImmutable $from, \DateTimeImmutable $to) use ($dataset, $time, $measure, $dashboard, $widget): float {
                $filters = array_values(array_filter($this->filters->merge($dashboard, $widget), fn ($filter) => ($filter['column'] ?? '') !== $time->physical_name));
                $filters[] = ['column' => $time->physical_name, 'operator' => 'gte', 'value' => $from->format('Y-m-d')];
                $filters[] = ['column' => $time->physical_name, 'operator' => 'lt', 'value' => $to->format('Y-m-d')];
                $spec = $this->validator->validate($dataset, ['measures' => [$measure], 'filters' => $filters, 'limit' => 1]);

                return (float) ($this->runner->run($spec)->rows[0]['m0'] ?? 0);
            };
            $prior = $value($previous, $current);
            if ($prior == 0) {
                return null;
            }

            return 100 * ($value($current, $current->modify('+1 month')) - $prior) / abs($prior);
        } catch (\Throwable) {
            // A KPI remains useful when a trend cannot be computed (for example
            // a source changes dates during rematerialization).
            return null;
        }
    }

    /**
     * Render a whole board.
     *
     * The dataset relation is eager-loaded for all of them up front: the spec
     * builder reads `$widget->dataset`, and forty widgets lazy-loading it one
     * at a time is the N+1 `tests/Feature/Perf/` exists to catch. The columns
     * underneath are loaded per dataset rather than per widget for the same
     * reason — several cards over one upload share its registry.
     *
     * @param  Collection<int, BiWidget>|null  $widgets
     * @return Collection<int, RenderedWidget>
     */
    public function renderBoard(BiDashboard $dashboard, ?Collection $widgets = null, bool $fresh = false): Collection
    {
        $widgets ??= $dashboard->widgets()->get();

        $widgets->loadMissing('dataset.columns');

        return $widgets->map(fn (BiWidget $widget) => $this->render($widget, $dashboard, $fresh));
    }
}
