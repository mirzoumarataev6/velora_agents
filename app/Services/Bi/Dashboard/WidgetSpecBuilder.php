<?php

namespace App\Services\Bi\Dashboard;

use App\Models\BiDashboard;
use App\Models\BiWidget;
use App\Services\Bi\Query\BiQueryException;
use App\Services\Bi\Query\QuerySpec;
use App\Services\Bi\Query\SpecValidator;

class WidgetSpecBuilder
{
    public function __construct(
        protected readonly SpecValidator $validator = new SpecValidator,
        protected readonly FilterMerger $filters = new FilterMerger,
    ) {}

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

        return [];
    }

    protected function limit(array $spec, WidgetType $type): int
    {
        $asked = (int) ($spec['limit'] ?? 0);

        return $asked > 0 ? $asked : $type->defaultLimit();
    }

    protected function listOf(array $spec, string $key): array
    {
        $value = $spec[$key] ?? [];

        return is_array($value) ? array_values($value) : [];
    }
}
