<?php

namespace App\Services\Bi\Dashboard;

use App\Models\BiDashboard;
use App\Models\BiWidget;

/**
 * Combines a board's filters with a card's own.
 *
 * THE RULE, and the reasoning behind each half:
 *
 *  1. Filters on DIFFERENT columns are AND-ed. "Region = Душанбе" on the board
 *     and "revenue > 1000" on the card means both, which is what an owner who
 *     set two filters expects.
 *
 *  2. Filters on the SAME column: the WIDGET wins, and the dashboard's is
 *     dropped entirely rather than AND-ed with it. This is the decision worth
 *     explaining, because AND-ing is the obvious implementation and it is
 *     wrong. A board filtered to "region = Душанбе" holding one card
 *     deliberately scoped to "region = Худжанд" would, under AND, return
 *     nothing at all — and an empty chart reads as "no sales", not as "your two
 *     filters contradict each other". The specific setting beats the general
 *     one, the same way a per-widget override beats a page-level default
 *     everywhere else in this product.
 *
 *     The cost is real and worth stating: an owner who sets a board filter and
 *     does not notice a card overriding it will see a card that disagrees with
 *     its neighbours. The UI marks those cards, which is why
 *     `overriddenColumns()` is part of this class's output rather than an
 *     internal detail.
 *
 * Column identity is the reference STRING as written (`c3`, or an id), not a
 * resolved `bi_columns` row. Resolution happens later in SpecValidator, and
 * doing it here would mean loading the registry twice per widget. Two spellings
 * of the same column — an id in one filter and `c3` in the other — therefore
 * both survive, and AND together. That is a deliberately conservative failure:
 * the editor writes physical names consistently, and the worst case is an
 * extra, redundant condition rather than a dropped one.
 */
class FilterMerger
{
    /**
     * @return array<int, array<string, mixed>>
     */
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

            // Rule 2: the card has already spoken about this column.
            if (isset($claimed[$this->columnKey($filter)])) {
                continue;
            }

            $merged[] = $filter;
        }

        return array_merge($merged, $widgetFilters);
    }

    /**
     * Which of the board's filters this card is overriding — what the UI needs
     * to mark the card as not obeying the page.
     *
     * @return array<int, string>
     */
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

    /**
     * @param  array<int, array<string, mixed>>  $filters
     * @return array<string, bool>
     */
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

    /**
     * @param  array<string, mixed>  $filter
     */
    private function columnKey(array $filter): string
    {
        $column = $filter['column'] ?? '';

        return is_scalar($column) ? (string) $column : '';
    }
}
