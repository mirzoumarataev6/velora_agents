<?php

namespace App\Services\Bi\Dashboard;

use App\Models\BiColumn;
use App\Models\BiDashboard;
use App\Models\BiDataset;
use App\Models\BiSource;
use App\Models\BiWidget;
use App\Models\User;
use App\Services\Bi\Query\QueryRunner;
use App\Services\Bi\Query\SpecValidator;
use App\Services\Bi\Schema\ColumnRole;
use Illuminate\Support\Facades\DB;

/**
 * Compose a useful first analysis from the schema, without asking an LLM to
 * guess columns or invent numbers. Every card is validated before the first
 * row is persisted; an unsuitable shape is omitted, never saved broken.
 */
class AutoAnalysisGenerator
{
    public function __construct(
        private readonly WidgetSpecBuilder $builder,
        private readonly SpecValidator $validator,
        private readonly QueryRunner $runner,
    ) {}

    public function generate(User $owner, BiSource $source, ?BiDataset $selected = null): BiDashboard
    {
        $dataset = $selected ?? $source->datasets()
            ->where('status', BiDataset::STATUS_READY)
            ->orderByDesc('row_count')->orderBy('id')->firstOrFail();

        if ($dataset->bi_source_id !== $source->id || $dataset->user_id !== $owner->id || ! $dataset->isReady()) {
            abort(404);
        }

        $dataset->load('columns');
        $columns = $dataset->columns;
        $time = $columns->first(fn (BiColumn $c) => $c->type->isTemporal() && $c->role === ColumnRole::Time);
        $numeric = $columns->filter(fn (BiColumn $c) => $c->type->isNumeric() && $c->role === ColumnRole::Measure)->values();
        $categories = $columns->filter(fn (BiColumn $c) => $c->role === ColumnRole::Dimension && $c->type->value === 'text' && ! $c->isLongText())->values();
        $key = $numeric->first(fn (BiColumn $c) => preg_match('/выруч|revenue|sales|sales amount|amount|сумм|total/iu', $c->displayName())) ?? $numeric->first();
        $category = $categories->first(fn (BiColumn $c) => preg_match('/регион|region|город|city/iu', $c->displayName()))
            ?? $categories->first(fn (BiColumn $c) => (int) $c->distinct_count > 1) ?? $categories->first();
        $shareCategory = $categories->first(fn (BiColumn $c) => $c->id !== $category?->id && (int) $c->distinct_count > 1 && (int) $c->distinct_count <= 6) ?? $category;
        $product = $categories->first(fn (BiColumn $c) => preg_match('/товар|product|item|услуг/iu', $c->displayName())) ?? $category;

        $templates = [];
        $put = function (string $title, WidgetType $type, array $spec, int $x, int $y, int $w, int $h) use (&$templates): void {
            $templates[] = compact('title', 'type', 'spec', 'x', 'y', 'w', 'h');
        };
        $measure = $key ? ['aggregate' => 'sum', 'column' => $key->physical_name] : ['aggregate' => 'count'];
        $put($key ? __('bi.analysis.total', ['column' => $key->displayName()]) : __('bi.analysis.rows'), WidgetType::Kpi, ['measures' => [$measure]], 0, 0, 3, 3);
        $put(__('bi.analysis.rows'), WidgetType::Kpi, ['measures' => [['aggregate' => 'count']]], 3, 0, 3, 3);
        if ($key) {
            $put(__('bi.analysis.average', ['column' => $key->displayName()]), WidgetType::Kpi, ['measures' => [['aggregate' => 'avg', 'column' => $key->physical_name]]], 6, 0, 3, 3);
            $other = $numeric->first(fn (BiColumn $c) => $c->id !== $key->id);
            if ($other) {
                $put(__('bi.analysis.total', ['column' => $other->displayName()]), WidgetType::Kpi, ['measures' => [['aggregate' => 'sum', 'column' => $other->physical_name]]], 9, 0, 3, 3);
            }
        }

        $grain = $time ? $this->grain($dataset, $time) : null;
        if ($time) {
            $put(__('bi.analysis.over_time', ['column' => $key?->displayName() ?? __('bi.analysis.rows')]), WidgetType::Area, [
                'dimensions' => [['column' => $time->physical_name, 'grain' => $grain]], 'measures' => [$measure],
            ], 0, 3, 12, 4);
        }
        $y = $time ? 7 : 3;
        if ($category) {
            $put(__('bi.analysis.by', ['column' => $category->displayName()]), WidgetType::Column, [
                'dimensions' => [['column' => $category->physical_name]], 'measures' => [$measure], 'limit' => 10,
            ], 0, $y, 6, 4);
            if ($shareCategory && (int) $shareCategory->distinct_count <= 6 && (int) $shareCategory->distinct_count > 1) {
                $put(__('bi.analysis.share', ['column' => $shareCategory->displayName()]), WidgetType::Donut, [
                    'dimensions' => [['column' => $shareCategory->physical_name]], 'measures' => [$measure],
                ], 6, $y, 6, 4);
            }
            $put(__('bi.analysis.top', ['column' => $product->displayName()]), WidgetType::Table, [
                'dimensions' => [['column' => $product->physical_name]], 'measures' => [$measure],
                'sorts' => [['field' => 'm0', 'direction' => 'desc']], 'limit' => 10,
            ], 0, $y + 4, $time ? 12 : 6, 4);
            if ($time && (int) $category->distinct_count > 1 && (int) $category->distinct_count <= 6) {
                $put(__('bi.analysis.by_time', ['column' => $category->displayName()]), WidgetType::Column, [
                    'dimensions' => [['column' => $time->physical_name, 'grain' => $grain], ['column' => $category->physical_name]],
                    'measures' => [$measure], 'sorts' => [['field' => 'd0', 'direction' => 'asc']], 'limit' => 200,
                ], 0, $y + 8, 12, 4);
                $templates[array_key_last($templates)]['options'] = ['stacked' => true];
            }
        }
        $details = $columns->take(6)->map(fn (BiColumn $c) => ['column' => $c->physical_name])->all();
        $detailY = $category && $time ? $y + 12 : ($category ? $y + 4 : $y);
        $put(__('bi.analysis.details'), WidgetType::Table, ['dimensions' => $details, 'detail' => true, 'limit' => 50], $category && ! $time ? 6 : 0, $detailY, $category && ! $time ? 6 : 12, 4);

        // Validate every candidate against the live column registry and widget
        // shape. No data query is needed here; rendering remains lazy/cached.
        $valid = [];
        foreach ($templates as $template) {
            $widget = new BiWidget(['type' => $template['type'], 'spec' => $template['spec']]);
            $widget->setRelation('dataset', $dataset);
            $this->builder->build($widget);
            $valid[] = $template;
        }

        return DB::transaction(function () use ($owner, $source, $dataset, $valid): BiDashboard {
            $dashboard = new BiDashboard(['name' => $source->name, 'position' => (int) BiDashboard::ownedBy($owner)->max('position') + 1]);
            $dashboard->user_id = $owner->id;
            $dashboard->bi_source_id = $source->id;
            $dashboard->bi_dataset_id = $dataset->id;
            $dashboard->save();
            if (BiDashboard::ownedBy($owner)->count() === 1) {
                $dashboard->makeDefault();
            }

            foreach ($valid as $item) {
                $widget = new BiWidget([
                    'title' => $item['title'], 'type' => $item['type'], 'spec' => $item['spec'],
                    'options' => $item['options'] ?? [],
                    'grid_x' => $item['x'], 'grid_y' => $item['y'], 'grid_w' => $item['w'], 'grid_h' => $item['h'],
                ]);
                $widget->user_id = $owner->id;
                $widget->bi_dashboard_id = $dashboard->id;
                $widget->bi_dataset_id = $dataset->id;
                $widget->save();
            }

            return $dashboard;
        });
    }

    private function grain(BiDataset $dataset, BiColumn $time): string
    {
        try {
            $spec = $this->validator->validate($dataset, ['measures' => [
                ['aggregate' => 'min', 'column' => $time->physical_name],
                ['aggregate' => 'max', 'column' => $time->physical_name],
            ], 'limit' => 1]);
            $row = $this->runner->run($spec)->rows[0] ?? [];
            $days = (new \DateTimeImmutable((string) ($row['m0'] ?? 'now')))->diff(new \DateTimeImmutable((string) ($row['m1'] ?? 'now')))->days;

            return match (true) {
                $days <= 45 => 'day', $days <= 150 => 'week', $days <= 730 => 'month', $days <= 1825 => 'quarter', default => 'year',
            };
        } catch (\Throwable) {
            return 'month';
        }
    }
}
