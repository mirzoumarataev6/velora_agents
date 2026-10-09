<?php

namespace Tests\Unit\Bi;

use App\Models\BiDashboard;
use App\Models\BiWidget;
use App\Services\Bi\Dashboard\FilterMerger;
use Tests\TestCase;

/**
 * How a board's filters and a card's own combine.
 *
 * Pure — no database, no HTTP — because the rule is pure, and the rule is the
 * only interesting thing here. The specific-beats-general half of it is a
 * decision that looks like a bug if you only read the code, so it is pinned by
 * a test that says why.
 */
class FilterMergerTest extends TestCase
{
    private FilterMerger $merger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merger = new FilterMerger;
    }

    private function dashboard(array $filtersByDataset): BiDashboard
    {
        $dashboard = new BiDashboard;
        $dashboard->forceFill(['filters' => $filtersByDataset]);

        return $dashboard;
    }

    private function widget(array $own, int $datasetId = 7): BiWidget
    {
        $widget = new BiWidget;
        $widget->forceFill(['filters' => $own]);
        $widget->bi_dataset_id = $datasetId;

        return $widget;
    }

    public function test_filters_on_different_columns_are_combined(): void
    {
        $merged = $this->merger->merge(
            $this->dashboard(['7' => [['column' => 'c1', 'operator' => 'eq', 'value' => 'Душанбе']]]),
            $this->widget([['column' => 'c2', 'operator' => 'gt', 'value' => 1000]])
        );

        $this->assertCount(2, $merged);
        $this->assertSame('c1', $merged[0]['column']);
        $this->assertSame('c2', $merged[1]['column']);
    }

    /**
     * THE RULE WORTH A TEST: the widget wins, and the board's filter on that
     * column is DROPPED rather than AND-ed.
     *
     * AND-ing is the obvious implementation. It is also how a board filtered to
     * Душанбе holding a card deliberately scoped to Худжанд returns nothing at
     * all — and an empty chart reads as "no sales", not as "your two filters
     * contradict each other".
     */
    public function test_the_widget_wins_on_a_column_both_filter(): void
    {
        $merged = $this->merger->merge(
            $this->dashboard(['7' => [['column' => 'c1', 'operator' => 'eq', 'value' => 'Душанбе']]]),
            $this->widget([['column' => 'c1', 'operator' => 'eq', 'value' => 'Худжанд']])
        );

        $this->assertCount(1, $merged);
        $this->assertSame('Худжанд', $merged[0]['value']);
    }

    public function test_an_override_is_reported_so_the_card_can_say_so(): void
    {
        $dashboard = $this->dashboard(['7' => [
            ['column' => 'c1', 'operator' => 'eq', 'value' => 'Душанбе'],
            ['column' => 'c2', 'operator' => 'gt', 'value' => 0],
        ]]);

        $widget = $this->widget([['column' => 'c1', 'operator' => 'eq', 'value' => 'Худжанд']]);

        $this->assertSame(['c1'], $this->merger->overriddenColumns($dashboard, $widget));
        $this->assertSame([], $this->merger->overriddenColumns($dashboard, $this->widget([])));
    }

    /**
     * A board can mix datasets, and "region = Душанбе" means nothing to an
     * upload with no region column — so the filters are keyed by dataset and
     * only the matching group applies.
     */
    public function test_only_the_filters_for_this_widgets_dataset_apply(): void
    {
        $dashboard = $this->dashboard([
            '7' => [['column' => 'c1', 'operator' => 'eq', 'value' => 'Душанбе']],
            '9' => [['column' => 'c4', 'operator' => 'eq', 'value' => 'Другое']],
        ]);

        $merged = $this->merger->merge($dashboard, $this->widget([], datasetId: 7));

        $this->assertCount(1, $merged);
        $this->assertSame('c1', $merged[0]['column']);
    }

    public function test_integer_and_string_dataset_keys_both_resolve(): void
    {
        // JSON round-trips object keys as strings; a filter saved through the
        // form and one written by a factory must behave identically.
        foreach ([['7' => [['column' => 'c1', 'operator' => 'eq', 'value' => 'x']]],
            [7 => [['column' => 'c1', 'operator' => 'eq', 'value' => 'x']]]] as $filters) {
            $merged = $this->merger->merge($this->dashboard($filters), $this->widget([], 7));

            $this->assertCount(1, $merged);
        }
    }

    public function test_a_widget_with_no_dashboard_keeps_only_its_own(): void
    {
        $merged = $this->merger->merge(null, $this->widget([['column' => 'c2', 'operator' => 'gt', 'value' => 1]]));

        $this->assertCount(1, $merged);
    }

    public function test_malformed_filter_entries_are_skipped_rather_than_fatal(): void
    {
        $merged = $this->merger->merge(
            $this->dashboard(['7' => ['not-an-array', ['column' => 'c1', 'operator' => 'eq', 'value' => 'x']]]),
            $this->widget([])
        );

        $this->assertCount(1, $merged);
    }
}
