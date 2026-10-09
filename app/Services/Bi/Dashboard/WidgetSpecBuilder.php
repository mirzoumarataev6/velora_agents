<?php

namespace App\Services\Bi\Dashboard;

use App\Models\BiDashboard;
use App\Models\BiWidget;
use App\Services\Bi\Query\BiQueryException;
use App\Services\Bi\Query\QuerySpec;
use App\Services\Bi\Query\SpecValidator;

/**
 * Turns a stored widget into a validated QuerySpec.
 *
 * Three things happen here, in this order, and the order is the design:
 *
 *  1. THE SHAPE IS CHECKED against the widget's type. A donut with two measures
 *     or a line chart with no dimension is refused here, with a code the UI can
 *     translate — rather than passed down to render as a chart with one slice
 *     or no x-axis. The query layer would happily answer either; the question
 *     is simply not one this shape can draw.
 *  2. DEFAULTS ARE APPLIED — sort and limit, from the type. These are added
 *     only where the owner has not chosen, so a default can never override an
 *     explicit choice.
 *  3. FILTERS ARE MERGED and the whole thing is handed to SpecValidator, which
 *     is the only component that resolves a column reference against the
 *     registry. Nothing here looks at `bi_columns` at all — this class composes
 *     an untrusted array out of two other untrusted arrays, and the trust
 *     boundary stays exactly where Phase 3 put it.
 */
class WidgetSpecBuilder
{
    public function __construct(
        protected readonly SpecValidator $validator = new SpecValidator,
        protected readonly FilterMerger $filters = new FilterMerger,
    ) {}

    /**
     * @throws BiQueryException
     */
    public function build(BiWidget $widget, ?BiDashboard $dashboard = null): QuerySpec
    {
        $type = $widget->type;
        $spec = is_array($widget->spec) ? $widget->spec : [];

        $dimensions = $this->listOf($spec, 'dimensions');
        $measures = $this->listOf($spec, 'measures');

        $this->assertShape($type, count($dimensions), count($measures));

        $input = [
            'detail' => $type === WidgetType::Table && ($spec['detail'] ?? false) === true,
            'dimensions' => $dimensions,
            'measures' => $measures,
            'filters' => $this->filters->merge($dashboard, $widget),
            'sorts' => $this->sorts($spec, $type, $dimensions, $measures),
            'limit' => $this->limit($spec, $type),
            'offset' => max(0, (int) ($spec['offset'] ?? 0)),
        ];

        return $this->validator->validate($widget->dataset, $input);
    }

    /**
     * @throws BiQueryException
     */
    protected function assertShape(WidgetType $type, int $dimensions, int $measures): void
    {
        [$minD, $maxD] = $type->dimensionRange();
        [$minM, $maxM] = $type->measureRange();

        if ($dimensions < $minD || $dimensions > $maxD) {
            throw new BiQueryException(
                BiQueryException::WIDGET_SHAPE,
                "A {$type->value} widget takes between {$minD} and {$maxD} dimensions, got {$dimensions}."
            );
        }

        if ($measures < $minM || $measures > $maxM) {
            throw new BiQueryException(
                BiQueryException::WIDGET_SHAPE,
                "A {$type->value} widget takes between {$minM} and {$maxM} measures, got {$measures}."
            );
        }
    }

    /**
     * The sort to apply, which is the owner's if they chose one.
     *
     * Where they did not, the type decides — and the two rules pull in opposite
     * directions on purpose. A bar chart of regions is almost always wanted
     * ranked, biggest first. A line chart over months must stay in date order,
     * because a line whose x-axis is sorted by value is not a line, it is a
     * descending staircase that looks like a collapse.
     *
     * @param  array<string, mixed>  $spec
     * @param  array<int, mixed>  $dimensions
     * @param  array<int, mixed>  $measures
     * @return array<int, array<string, mixed>>
     */
    protected function sorts(array $spec, WidgetType $type, array $dimensions, array $measures): array
    {
        $chosen = $this->listOf($spec, 'sorts');

        if ($chosen !== []) {
            return $chosen;
        }

        if ($type->ranksByDefault() && $measures !== []) {
            return [['field' => 'm0', 'direction' => 'desc']];
        }

        if ($type->isSequential() && $dimensions !== []) {
            return [['field' => 'd0', 'direction' => 'asc']];
        }

        // A table and a KPI get whatever order the engine returns, which for a
        // KPI is one row and for a table is the owner's own choice to make.
        return [];
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    protected function limit(array $spec, WidgetType $type): int
    {
        $asked = (int) ($spec['limit'] ?? 0);

        return $asked > 0 ? $asked : $type->defaultLimit();
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array<int, mixed>
     */
    protected function listOf(array $spec, string $key): array
    {
        $value = $spec[$key] ?? [];

        return is_array($value) ? array_values($value) : [];
    }
}
