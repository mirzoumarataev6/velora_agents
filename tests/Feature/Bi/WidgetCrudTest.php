<?php

namespace Tests\Feature\Bi;

use App\Models\BiColumn;
use App\Models\BiDashboard;
use App\Models\BiDataset;
use App\Models\BiWidget;
use App\Models\User;
use App\Services\Bi\Dashboard\WidgetType;
use App\Services\Bi\Ingestion\DatasetMaterializer;
use App\Services\Bi\Ingestion\SourceNormalizer;
use App\Services\Bi\Schema\ColumnRole;
use App\Services\Bi\Schema\ColumnType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsBiFixtures;
use Tests\Support\DropsAnalyticalTables;
use Tests\TestCase;

/**
 * Cards: adding, editing, removing — and the two authorizations every one of
 * those performs.
 */
class WidgetCrudTest extends TestCase
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
        $this->dataset = $this->salesDataset($this->user);
    }

    private function salesDataset(User $user): BiDataset
    {
        $source = $this->csvSource($user, [
            ['Дата', 'Регион', 'Выручка'],
            ['05.01.2026', 'Душанбе', '1000.00'],
            ['03.02.2026', 'Худжанд', '2000.00'],
        ]);

        $datasets = app(SourceNormalizer::class)->normalize($source);
        app(DatasetMaterializer::class)->materialize($datasets[0]);

        return $datasets[0]->fresh();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'bi_dataset_id' => $this->dataset->id,
            'title' => 'Выручка по регионам',
            'type' => 'column',
            'spec' => [
                'dimensions' => [['column' => 'c1']],
                'measures' => [['aggregate' => 'sum', 'column' => 'c2']],
            ],
        ], $overrides);
    }

    public function test_a_widget_can_be_added(): void
    {
        $this->actingAs($this->user)
            ->post(route('bi.widgets.store', $this->dashboard), $this->payload())
            ->assertRedirect();

        $widget = BiWidget::firstOrFail();

        $this->assertSame($this->user->id, $widget->user_id);
        $this->assertSame($this->dashboard->id, $widget->bi_dashboard_id);
        $this->assertSame(WidgetType::Column, $widget->type);
    }

    /**
     * A new card goes to the BOTTOM rather than into the first gap — gridstack
     * would float it upward, which is startling when the owner has just pressed
     * "add" and is looking at the end of the board.
     */
    public function test_a_new_widget_is_placed_below_the_existing_ones(): void
    {
        BiWidget::factory()->create([
            'user_id' => $this->user->id,
            'bi_dashboard_id' => $this->dashboard->id,
            'bi_dataset_id' => $this->dataset->id,
            'grid_y' => 0, 'grid_h' => 4,
        ]);

        $this->actingAs($this->user)->post(route('bi.widgets.store', $this->dashboard), $this->payload());

        $this->assertSame(4, BiWidget::latest('id')->first()->grid_y);
    }

    public function test_a_kpi_gets_a_smaller_default_slot(): void
    {
        $this->actingAs($this->user)->post(route('bi.widgets.store', $this->dashboard), $this->payload([
            'type' => 'kpi',
            'spec' => ['measures' => [['aggregate' => 'sum', 'column' => 'c2']]],
        ]));

        $widget = BiWidget::firstOrFail();

        $this->assertSame(3, $widget->grid_w);
        $this->assertSame(2, $widget->grid_h);
    }

    public function test_a_widget_can_be_edited(): void
    {
        $widget = BiWidget::factory()->create([
            'user_id' => $this->user->id,
            'bi_dashboard_id' => $this->dashboard->id,
            'bi_dataset_id' => $this->dataset->id,
        ]);

        $this->actingAs($this->user)
            ->patch(route('bi.widgets.update', [$this->dashboard, $widget]), $this->payload([
                'title' => 'Новое название',
                'type' => 'donut',
            ]))
            ->assertRedirect();

        $this->assertSame('Новое название', $widget->fresh()->title);
        $this->assertSame(WidgetType::Donut, $widget->fresh()->type);
    }

    public function test_a_widget_can_be_removed(): void
    {
        $widget = BiWidget::factory()->create([
            'user_id' => $this->user->id,
            'bi_dashboard_id' => $this->dashboard->id,
            'bi_dataset_id' => $this->dataset->id,
        ]);

        $this->actingAs($this->user)
            ->delete(route('bi.widgets.destroy', [$this->dashboard, $widget]))
            ->assertRedirect();

        $this->assertDatabaseMissing('bi_widgets', ['id' => $widget->id]);
    }

    public function test_json_editor_saves_and_returns_the_updated_card_without_a_redirect(): void
    {
        $response = $this->actingAs($this->user)->postJson(route('bi.widgets.store', $this->dashboard), $this->payload());
        $response->assertOk()->assertJsonPath('widget.type', 'column')->assertJsonStructure(['html', 'widget' => ['id', 'slot']]);

        $widget = BiWidget::firstOrFail();
        $this->actingAs($this->user)->patchJson(route('bi.widgets.update', [$this->dashboard, $widget]), $this->payload([
            'title' => 'Новая выручка', 'filters' => [['column' => 'c1', 'operator' => 'eq', 'value' => 'Душанбе']],
        ]))->assertOk()->assertJsonPath('widget.title', 'Новая выручка');

        $this->assertSame('Душанбе', $widget->fresh()->filters[0]['value']);
        $this->actingAs($this->user)->deleteJson(route('bi.widgets.destroy', [$this->dashboard, $widget]))
            ->assertOk()->assertJsonPath('id', $widget->id);
    }

    public function test_an_invalid_time_bucket_is_rejected_before_saving(): void
    {
        $this->actingAs($this->user)->postJson(route('bi.widgets.store', $this->dashboard), $this->payload([
            'spec' => ['dimensions' => [['column' => 'c1', 'grain' => 'day']], 'measures' => [['aggregate' => 'count']]],
        ]))->assertUnprocessable()->assertJsonValidationErrors('spec');

        $this->assertSame(0, BiWidget::count());
    }

    // --- The closed sets are enforced at the request layer -------------------

    public function test_an_unknown_type_aggregate_or_operator_is_refused(): void
    {
        $this->actingAs($this->user)
            ->post(route('bi.widgets.store', $this->dashboard), $this->payload(['type' => 'pyramid']))
            ->assertSessionHasErrors('type');

        $this->actingAs($this->user)
            ->post(route('bi.widgets.store', $this->dashboard), $this->payload([
                'spec' => ['measures' => [['aggregate' => 'median', 'column' => 'c2']]],
            ]))
            ->assertSessionHasErrors('spec.measures.0.aggregate');

        $this->actingAs($this->user)
            ->post(route('bi.widgets.store', $this->dashboard), $this->payload([
                'filters' => [['column' => 'c1', 'operator' => 'regex', 'value' => '.*']],
            ]))
            ->assertSessionHasErrors('filters.0.operator');
    }

    public function test_a_title_is_required(): void
    {
        $this->actingAs($this->user)
            ->post(route('bi.widgets.store', $this->dashboard), $this->payload(['title' => '']))
            ->assertSessionHasErrors('title');
    }

    public function test_a_board_is_capped_at_its_widget_limit(): void
    {
        BiWidget::factory()->count(BiDashboard::MAX_WIDGETS)->create([
            'user_id' => $this->user->id,
            'bi_dashboard_id' => $this->dashboard->id,
            'bi_dataset_id' => $this->dataset->id,
        ]);

        $this->actingAs($this->user)
            ->post(route('bi.widgets.store', $this->dashboard), $this->payload())
            ->assertSessionHasErrors('title');
    }

    // --- The second authorization: the dataset -------------------------------

    /**
     * Owning the BOARD is not enough. A widget names two things the caller must
     * own, and the dataset is the one that decides whose numbers are read.
     */
    public function test_a_widget_cannot_point_at_another_accounts_dataset(): void
    {
        $foreign = BiDataset::factory()->create([
            'user_id' => User::factory()->create()->id,
            'status' => BiDataset::STATUS_READY,
        ]);

        $this->actingAs($this->user)
            ->post(route('bi.widgets.store', $this->dashboard), $this->payload([
                'bi_dataset_id' => $foreign->id,
            ]))
            ->assertSessionHasErrors('bi_dataset_id');

        $this->assertSame(0, BiWidget::count());
    }

    public function test_repointing_an_existing_widget_at_a_foreign_dataset_is_refused(): void
    {
        $widget = BiWidget::factory()->create([
            'user_id' => $this->user->id,
            'bi_dashboard_id' => $this->dashboard->id,
            'bi_dataset_id' => $this->dataset->id,
        ]);

        $foreign = BiDataset::factory()->create([
            'user_id' => User::factory()->create()->id,
            'status' => BiDataset::STATUS_READY,
        ]);

        $this->actingAs($this->user)
            ->patch(route('bi.widgets.update', [$this->dashboard, $widget]), $this->payload([
                'bi_dataset_id' => $foreign->id,
            ]))
            ->assertSessionHasErrors('bi_dataset_id');

        $this->assertSame($this->dataset->id, $widget->fresh()->bi_dataset_id);
    }

    public function test_a_live_connection_dataset_can_be_used_when_its_columns_are_registered(): void
    {
        $external = BiDataset::factory()->create([
            'user_id' => $this->user->id,
            'status' => BiDataset::STATUS_READY,
            'external_table' => 'orders',
        ]);

        BiColumn::factory()->at(1)->type(ColumnType::Text, ColumnRole::Dimension)->create([
            'user_id' => $this->user->id, 'bi_dataset_id' => $external->id, 'source_name' => 'region',
        ]);
        BiColumn::factory()->at(2)->type(ColumnType::Decimal, ColumnRole::Measure)->create([
            'user_id' => $this->user->id, 'bi_dataset_id' => $external->id, 'source_name' => 'revenue',
        ]);

        $this->actingAs($this->user)
            ->post(route('bi.widgets.store', $this->dashboard), $this->payload(['bi_dataset_id' => $external->id]))
            ->assertRedirect();

        $this->assertSame($external->id, BiWidget::firstOrFail()->bi_dataset_id);
    }

    /**
     * Routes are scope-bound, so a widget id from another board is a 404 at the
     * router rather than a policy decision.
     */
    public function test_a_widget_from_another_board_is_not_found(): void
    {
        $other = BiDashboard::factory()->create(['user_id' => $this->user->id]);
        $widget = BiWidget::factory()->create([
            'user_id' => $this->user->id,
            'bi_dashboard_id' => $other->id,
            'bi_dataset_id' => $this->dataset->id,
        ]);

        $this->actingAs($this->user)
            ->delete(route('bi.widgets.destroy', [$this->dashboard, $widget]))
            ->assertNotFound();

        $this->assertDatabaseHas('bi_widgets', ['id' => $widget->id]);
    }

    public function test_another_account_cannot_add_a_widget_to_this_board(): void
    {
        $intruder = User::factory()->create();

        $this->actingAs($intruder)
            ->post(route('bi.widgets.store', $this->dashboard), $this->payload())
            ->assertForbidden();
    }
}
