<?php

namespace App\Services\Bi\Dashboard;

use App\Models\BiDashboard;
use App\Models\BiWidget;
use App\Services\Bi\Query\BiQueryException;
use App\Services\Bi\Query\QueryRunner;
use App\Services\Bi\Query\SpecValidator;
use App\Services\Bi\Schema\ColumnRole;
use Illuminate\Support\Collection;

class WidgetRenderer
{
    public function __construct(
        protected readonly WidgetSpecBuilder $specs = new WidgetSpecBuilder,
        protected readonly QueryRunner $runner = new QueryRunner,
        protected readonly FilterMerger $filters = new FilterMerger,
        protected readonly SpecValidator $validator = new SpecValidator,
    ) {}

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

            return null;
        }
    }

    public function renderBoard(BiDashboard $dashboard, ?Collection $widgets = null, bool $fresh = false): Collection
    {
        $widgets ??= $dashboard->widgets()->get();

        $widgets->loadMissing('dataset.columns');

        return $widgets->map(fn (BiWidget $widget) => $this->render($widget, $dashboard, $fresh));
    }
}
