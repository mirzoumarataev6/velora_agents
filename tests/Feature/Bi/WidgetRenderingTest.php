<?php

namespace Tests\Feature\Bi;

use App\Models\BiDashboard;
use App\Models\BiDataset;
use App\Models\BiWidget;
use App\Models\User;
use App\Services\Bi\Dashboard\WidgetRenderer;
use App\Services\Bi\Dashboard\WidgetType;
use App\Services\Bi\Ingestion\DatasetMaterializer;
use App\Services\Bi\Ingestion\SourceNormalizer;
use App\Services\Bi\Query\BiQueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsBiFixtures;
use Tests\Support\DropsAnalyticalTables;
use Tests\TestCase;

/**
 * Drawing a board: every chart type, the filter merge, the cache, and — the
 * one that matters most on a forty-card board — per-card failure isolation.
 */
class WidgetRenderingTest extends TestCase
{
    use BuildsBiFixtures, DropsAnalyticalTables, RefreshDatabase;

    private User $user;

    private BiDashboard $dashboard;

    private BiDataset $dataset;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->user = User::factory()->create();
        $this->dashboard = BiDashboard::factory()->create(['user_id' => $this->user->id]);

        $source = $this->csvSource($this->user, [
            ['Дата', 'Регион', 'Выручка'],
            ['05.01.2026', 'Душанбе', '1000.00'],
            ['12.01.2026', 'Душанбе', '500.00'],
            ['03.02.2026', 'Худжанд', '2000.00'],
            ['09.03.2026', 'Куляб', '750.00'],
        ]);

        $datasets = app(SourceNormalizer::class)->normalize($source);
        app(DatasetMaterializer::class)->materialize($datasets[0]);
        $this->dataset = $datasets[0]->fresh();
    }

    private function widget(WidgetType $type, array $spec, array $filters = []): BiWidget
    {
        return BiWidget::factory()->create([
            'user_id' => $this->user->id,
            'bi_dashboard_id' => $this->dashboard->id,
            'bi_dataset_id' => $this->dataset->id,
            'type' => $type,
            'spec' => $spec,
            'filters' => $filters ?: null,
        ]);
    }

    private function byRegion(): array
    {
        return [
            'dimensions' => [['column' => 'c1']],
            'measures' => [['aggregate' => 'sum', 'column' => 'c2']],
        ];
    }

    public function test_detail_table_keeps_duplicate_rows_and_supports_search_sort_and_pagination(): void
    {
        $widget = $this->widget(WidgetType::Table, [
            'detail' => true, 'dimensions' => [['column' => 'c0'], ['column' => 'c1'], ['column' => 'c2']], 'limit' => 2,
        ]);

        $page = $this->actingAs($this->user)->getJson(route('bi.widgets.data', [$this->dashboard, $widget]));
        $page->assertOk()->assertJsonCount(2, 'rows')->assertJsonPath('has_more', true);

        $this->actingAs($this->user)->getJson(route('bi.widgets.data', [$this->dashboard, $widget]).'?offset=2')
            ->assertOk()->assertJsonCount(2, 'rows')->assertJsonPath('has_more', false);

        // SQLite LOWER folds ASCII only; production MySQL folds Cyrillic. Use
        // ASCII here to exercise pagination/search without claiming parity the
        // test engine does not have.
        DB::table($this->dataset->physicalTable())->whereIn('_row', [0, 1])->update(['c1' => 'Dushanbe']);
        $this->actingAs($this->user)->getJson(route('bi.widgets.data', [$this->dashboard, $widget]).'?offset=0&search_column=c1&search=dushanbe')
            ->assertOk()->assertJsonCount(2, 'rows');

        $this->actingAs($this->user)->getJson(route('bi.widgets.data', [$this->dashboard, $widget]).'?sort=d2&direction=desc')
            ->assertOk()->assertJsonPath('rows.0.d2', 2000);
    }

    // --- Every chart type actually renders ----------------------------------

    /**
     * Each type, drawn for real, on a real board.
     *
     * The assertion is deliberately weak per type — that the page is 200 and
     * the card's title is on it — because the strong assertions about the
     * NUMBERS belong to the query layer's tests, and a brittle assertion on SVG
     * path data would fail every time a radius changed. What this proves is
     * that every one of the seven components compiles, receives the shape it
     * expects, and survives being handed a real QueryResult.
     */
    public function test_every_widget_type_renders_on_the_board(): void
    {
        $specs = [
            WidgetType::Line->value => ['dimensions' => [['column' => 'c0', 'grain' => 'month']], 'measures' => [['aggregate' => 'sum', 'column' => 'c2']]],
            WidgetType::Area->value => ['dimensions' => [['column' => 'c0', 'grain' => 'month']], 'measures' => [['aggregate' => 'sum', 'column' => 'c2']]],
            WidgetType::Bar->value => $this->byRegion(),
            WidgetType::Column->value => $this->byRegion(),
            WidgetType::Donut->value => $this->byRegion(),
            WidgetType::Table->value => $this->byRegion(),
            WidgetType::Kpi->value => ['measures' => [['aggregate' => 'sum', 'column' => 'c2']]],
        ];

        foreach ($specs as $type => $spec) {
            $this->widget(WidgetType::from($type), $spec)
                ->forceFill(['title' => 'Виджет '.$type])->save();
        }

        $response = $this->actingAs($this->user)->get(route('bi.dashboards.show', $this->dashboard));

        $response->assertOk();

        foreach (array_keys($specs) as $type) {
            $response->assertSee('Виджет '.$type);
        }
    }

    public function test_a_kpi_renders_the_single_total(): void
    {
        $this->widget(WidgetType::Kpi, ['measures' => [['aggregate' => 'sum', 'column' => 'c2']]]);

        $rendered = app(WidgetRenderer::class)->renderBoard($this->dashboard)->first();

        $this->assertFalse($rendered->failed());
        $this->assertSame(4250.0, (float) $rendered->result->rows[0]['m0']);
    }

    /**
     * A donut asks for one more category than it can colour, so it can tell
     * "exactly six" from "more than six" and fold the rest into one neutral
     * slice rather than reusing a hue.
     */
    public function test_a_donut_asks_for_one_more_category_than_it_can_colour(): void
    {
        $this->assertSame(WidgetType::SERIES_SLOTS + 1, WidgetType::Donut->defaultLimit());
    }

    // --- Shape rules ---------------------------------------------------------

    /**
     * A valid question that this shape cannot draw is refused with its own
     * code — not rendered as a pie with one slice.
     */
    public function test_a_shape_the_type_cannot_draw_is_refused_per_card(): void
    {
        $widget = $this->widget(WidgetType::Donut, [
            'dimensions' => [['column' => 'c1']],
            'measures' => [
                ['aggregate' => 'sum', 'column' => 'c2'],
                ['aggregate' => 'count'],
            ],
        ]);

        $rendered = app(WidgetRenderer::class)->render($widget, $this->dashboard);

        $this->assertTrue($rendered->failed());
        $this->assertSame(BiQueryException::WIDGET_SHAPE, $rendered->errorCode);
        $this->assertFalse($rendered->transient);
    }

    public function test_a_kpi_with_a_dimension_is_refused(): void
    {
        $widget = $this->widget(WidgetType::Kpi, $this->byRegion());

        $this->assertSame(
            BiQueryException::WIDGET_SHAPE,
            app(WidgetRenderer::class)->render($widget, $this->dashboard)->errorCode
        );
    }

    // --- Type defaults -------------------------------------------------------

    /**
     * A bar chart ranks; a line chart does not. Sorting a time axis by value
     * turns a line into a descending staircase that looks like a collapse.
     */
    public function test_ranking_types_sort_by_value_and_sequential_types_by_time(): void
    {
        $ranked = app(WidgetRenderer::class)
            ->render($this->widget(WidgetType::Column, $this->byRegion()), $this->dashboard);

        $this->assertSame(['Худжанд', 'Душанбе', 'Куляб'], $ranked->result->column('d0'));

        $sequential = app(WidgetRenderer::class)->render(
            $this->widget(WidgetType::Line, [
                'dimensions' => [['column' => 'c0', 'grain' => 'month']],
                'measures' => [['aggregate' => 'sum', 'column' => 'c2']],
            ]),
            $this->dashboard
        );

        $this->assertSame(['2026-01-01', '2026-02-01', '2026-03-01'], $sequential->result->column('d0'));
    }

    /** An explicit sort always beats the type's default. */
    public function test_an_explicit_sort_overrides_the_type_default(): void
    {
        $widget = $this->widget(WidgetType::Column, array_merge($this->byRegion(), [
            'sorts' => [['field' => 'd0', 'direction' => 'asc']],
        ]));

        $this->assertSame(
            ['Душанбе', 'Куляб', 'Худжанд'],
            app(WidgetRenderer::class)->render($widget, $this->dashboard)->result->column('d0')
        );
    }

    // --- Filters -------------------------------------------------------------

    public function test_a_dashboard_filter_narrows_every_card_over_that_dataset(): void
    {
        $this->dashboard->forceFill([
            'filters' => [(string) $this->dataset->id => [
                ['column' => 'c1', 'operator' => 'eq', 'value' => 'Душанбе'],
            ]],
        ])->save();

        $rendered = app(WidgetRenderer::class)
            ->render($this->widget(WidgetType::Column, $this->byRegion()), $this->dashboard->fresh());

        $this->assertSame(['Душанбе'], $rendered->result->column('d0'));
        $this->assertSame(1500.0, (float) $rendered->result->rows[0]['m0']);
    }

    /**
     * The card's own filter wins, and the card SAYS it is overriding — the
     * alternative is one card quietly disagreeing with its neighbours.
     */
    public function test_a_widget_filter_overrides_the_board_and_is_reported(): void
    {
        $this->dashboard->forceFill([
            'filters' => [(string) $this->dataset->id => [
                ['column' => 'c1', 'operator' => 'eq', 'value' => 'Душанбе'],
            ]],
        ])->save();

        $widget = $this->widget(WidgetType::Column, $this->byRegion(), [
            ['column' => 'c1', 'operator' => 'eq', 'value' => 'Худжанд'],
        ]);

        $rendered = app(WidgetRenderer::class)->render($widget, $this->dashboard->fresh());

        $this->assertSame(['Худжанд'], $rendered->result->column('d0'));
        $this->assertSame(['c1'], $rendered->overriddenFilters);
    }

    // --- Cache ---------------------------------------------------------------

    /**
     * The cache lives in QueryRunner and is keyed by the SPEC, not the widget —
     * so changing a filter is automatically a different key, and two cards
     * asking the same question share one entry.
     */
    public function test_a_second_render_is_served_from_cache(): void
    {
        $widget = $this->widget(WidgetType::Column, $this->byRegion());

        $this->assertFalse(app(WidgetRenderer::class)->render($widget, $this->dashboard)->cached());
        $this->assertTrue(app(WidgetRenderer::class)->render($widget, $this->dashboard)->cached());
    }

    public function test_changing_a_filter_is_a_different_cache_entry(): void
    {
        $widget = $this->widget(WidgetType::Column, $this->byRegion());

        app(WidgetRenderer::class)->render($widget, $this->dashboard);

        $widget->forceFill(['filters' => [['column' => 'c1', 'operator' => 'eq', 'value' => 'Душанбе']]])->save();

        $this->assertFalse(app(WidgetRenderer::class)->render($widget->fresh(), $this->dashboard)->cached());
    }

    public function test_two_cards_asking_the_same_question_share_one_entry(): void
    {
        $a = $this->widget(WidgetType::Column, $this->byRegion());
        $b = $this->widget(WidgetType::Bar, $this->byRegion());

        app(WidgetRenderer::class)->render($a, $this->dashboard);

        // Different card, different type, same question — and `bar` and
        // `column` share a default sort, so the specs are identical.
        $this->assertTrue(app(WidgetRenderer::class)->render($b, $this->dashboard)->cached());
    }

    public function test_the_refresh_link_bypasses_the_cache(): void
    {
        $this->widget(WidgetType::Column, $this->byRegion());

        $this->actingAs($this->user)->get(route('bi.dashboards.show', $this->dashboard))->assertOk();

        $this->actingAs($this->user)
            ->get(route('bi.dashboards.show', [$this->dashboard, 'fresh' => 1]))
            ->assertOk()
            ->assertDontSee(__('bi.widgets.cached'));
    }

    // --- Failure isolation ---------------------------------------------------

    /**
     * ONE BROKEN CARD MUST NOT TAKE THE BOARD DOWN.
     *
     * A board holds up to forty cards over several datasets, any of which can
     * be mid-rebuild or re-typed since the card was saved. If one failing card
     * threw, the owner would lose thirty-nine working charts.
     */
    public function test_one_failing_card_does_not_break_the_board(): void
    {
        $good = $this->widget(WidgetType::Column, $this->byRegion());
        $good->forceFill(['title' => 'Рабочий виджет'])->save();

        $broken = $this->widget(WidgetType::Column, [
            'dimensions' => [['column' => 'c99']],
            'measures' => [['aggregate' => 'sum', 'column' => 'c2']],
        ]);
        $broken->forceFill(['title' => 'Сломанный виджет'])->save();

        $response = $this->actingAs($this->user)->get(route('bi.dashboards.show', $this->dashboard));

        $response->assertOk()
            ->assertSee('Рабочий виджет')
            ->assertSee('Сломанный виджет')
            ->assertSee(__('bi.errors.unknown_column'));
    }

    /**
     * A card whose table is being rebuilt offers a retry; one whose column is
     * gone does not. Offering "try again" on a permanent refusal is a lie.
     */
    public function test_only_transient_failures_offer_a_retry(): void
    {
        $widget = $this->widget(WidgetType::Column, $this->byRegion());

        Schema::drop($this->dataset->physicalTable());

        $rendered = app(WidgetRenderer::class)->render($widget, $this->dashboard);

        $this->assertSame(BiQueryException::TABLE_MISSING, $rendered->errorCode);
        $this->assertTrue($rendered->transient);
    }

    /** Empty is not an error, and says something more useful than one. */
    public function test_a_card_with_no_matching_rows_says_so_rather_than_failing(): void
    {
        $widget = $this->widget(WidgetType::Column, $this->byRegion(), [
            ['column' => 'c1', 'operator' => 'eq', 'value' => 'Нигде'],
        ]);

        $rendered = app(WidgetRenderer::class)->render($widget, $this->dashboard);

        $this->assertFalse($rendered->failed());
        $this->assertTrue($rendered->isEmpty());

        $this->actingAs($this->user)
            ->get(route('bi.dashboards.show', $this->dashboard))
            ->assertOk()
            ->assertSee(__('bi.widgets.no_data'));
    }

    // --- The per-card data endpoint -----------------------------------------

    public function test_the_data_endpoint_returns_the_cards_rows(): void
    {
        $widget = $this->widget(WidgetType::Column, $this->byRegion());

        $this->actingAs($this->user)
            ->getJson(route('bi.widgets.data', [$this->dashboard, $widget]))
            ->assertOk()
            ->assertJsonStructure(['rows', 'columns', 'has_more', 'cached', 'duration_ms']);
    }

    /**
     * A refused query is a 200 with an `error` key, not an HTTP error: the card
     * has already rendered, and what the browser needs is the sentence to put
     * inside it.
     */
    public function test_the_data_endpoint_reports_a_refusal_as_a_payload(): void
    {
        $widget = $this->widget(WidgetType::Column, [
            'dimensions' => [['column' => 'c99']],
            'measures' => [['aggregate' => 'sum', 'column' => 'c2']],
        ]);

        $this->actingAs($this->user)
            ->getJson(route('bi.widgets.data', [$this->dashboard, $widget]))
            ->assertOk()
            ->assertJson([
                'error' => BiQueryException::UNKNOWN_COLUMN,
                'message' => __('bi.errors.unknown_column'),
                'transient' => false,
            ]);
    }

    public function test_another_account_cannot_read_a_cards_data(): void
    {
        $widget = $this->widget(WidgetType::Column, $this->byRegion());

        $this->actingAs(User::factory()->create())
            ->getJson(route('bi.widgets.data', [$this->dashboard, $widget]))
            ->assertForbidden();
    }
}
