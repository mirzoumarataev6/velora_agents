<?php

namespace App\Services\Bi\Dashboard;

use App\Models\BiDashboard;
use App\Models\BiWidget;

class FilterMerger
{
    public function merge(?BiDashboard $dashboard, BiWidget $widget): array
    {
        $widgetFilters = $widget->ownFilters();

        if ($dashboard === null) {
            return $widgetFilters;
        }

        $claimed = $this->columnsOf($widgetFilters);

        $merged = [];

        foreach ($dashboard->filtersFor((int) $widget->bi_dataset_id) as $filter) {
            if (! is_array($filter)) {
                continue;
            }

            if (isset($claimed[$this->columnKey($filter)])) {
                continue;
            }

            $merged[] = $filter;
        }

        return array_merge($merged, $widgetFilters);
    }

    public function overriddenColumns(?BiDashboard $dashboard, BiWidget $widget): array
    {
        if ($dashboard === null) {
            return [];
        }

        $claimed = $this->columnsOf($widget->ownFilters());
        $overridden = [];

        foreach ($dashboard->filtersFor((int) $widget->bi_dataset_id) as $filter) {
            if (! is_array($filter)) {
                continue;
            }

            $key = $this->columnKey($filter);

            if ($key !== '' && isset($claimed[$key])) {
                $overridden[] = $key;
            }
        }

        return array_values(array_unique($overridden));
    }

    private function columnsOf(array $filters): array
    {
        $columns = [];

        foreach ($filters as $filter) {
            if (! is_array($filter)) {
                continue;
            }

            $key = $this->columnKey($filter);

            if ($key !== '') {
                $columns[$key] = true;
            }
        }

        return $columns;
    }

    private function columnKey(array $filter): string
    {
        $column = $filter['column'] ?? '';

        return is_scalar($column) ? (string) $column : '';
    }
}
