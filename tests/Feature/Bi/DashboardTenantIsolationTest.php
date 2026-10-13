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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsBiFixtures;
use Tests\Support\DropsAnalyticalTables;
use Tests\TestCase;

/**
 * Isolation at the DASHBOARD layer.
 *
 * `QueryTenantIsolationTest` proves the query layer cannot be made to read
 * another owner's table. This proves the layer above it cannot be made to ASK —
 * which is a different question, because a widget is a stored, replayable spec
 * that names a dataset by id, and the id is a number in a row that a request
 * could try to change.
 *
 * Both owners get identically-shaped datasets with disjoint values, so nothing
 * here passes merely because a column name was missing.
 */
class DashboardTenantIsolationTest extends TestCase
{
    use BuildsBiFixtures, DropsAnalyticalTables, RefreshDatabase;

    private User $owner;

    private User $intruder;

    private BiDashboard $ownerBoard;

    private BiDataset $ownerDataset;

    private BiDataset $intruderDataset;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->owner = User::factory()->create();
        $this->intruder = User::factory()->create();

        $this->ownerDataset = $this->datasetFor($this->owner, 'Душанбе', '1000.00');
        $this->intruderDataset = $this->datasetFor($this->intruder, 'Лондон', '777777.00');

        $this->ownerBoard = BiDashboard::factory()->create(['user_id' => $this->owner->id]);
    }

    private function datasetFor(User $user, string $region, string $revenue): BiDataset
    {
        $source = $this->csvSource($user, [
            ['Дата', 'Регион', 'Выручка'],
            ['05.01.2026', $region, $revenue],
        ]);

        $datasets = app(SourceNormalizer::class)->normalize($source);
        app(DatasetMaterializer::class)->materialize($datasets[0]);

        return $datasets[0]->fresh();
    }

    /**
     * THE ATTACK THIS FILE EXISTS FOR: a widget row that has been made to point
     * at another owner's dataset, bypassing the controller entirely.
     *
     * The widget belongs to the intruder and sits on their own board, so every
     * widget-level policy says yes. The only thing standing between them and
     * the owner's revenue is the SECOND authorization — the dataset's — which
     * is why the controller performs it separately. Here the row is forged
     * directly, so the test proves the read path refuses it too.
     */
    public function test_a_forged_widget_cannot_read_another_owners_dataset(): void
    {
        $intruderBoard = BiDashboard::factory()->create(['user_id' => $this->intruder->id]);

        $widget = BiWidget::factory()->create([
            'user_id' => $this->intruder->id,
            'bi_dashboard_id' => $intruderBoard->id,
            // Forged: the victim's dataset.
            'bi_dataset_id' => $this->ownerDataset->id,
            'type' => WidgetType::Column,
            'spec' => [
                'dimensions' => [['column' => 'c1']],
                'measures' => [['aggregate' => 'sum', 'column' => 'c2']],
            ],
        ]);

        // The data endpoint authorizes the dataset explicitly and refuses.
        $this->actingAs($this->intruder)
            ->getJson(route('bi.widgets.data', [$intruderBoard, $widget]))
            ->assertForbidden();
    }

    public function test_the_widget_policy_alone_is_not_what_protects_the_data(): void
    {
        $intruderBoard = BiDashboard::factory()->create(['user_id' => $this->intruder->id]);

        $widget = BiWidget::factory()->create([
            'user_id' => $this->intruder->id,
            'bi_dashboard_id' => $intruderBoard->id,
            'bi_dataset_id' => $this->ownerDataset->id,
        ]);

        // The widget IS theirs — which is exactly why one check is not enough.
        $this->assertTrue($this->intruder->can('view', $widget));
        $this->assertTrue($this->intruder->can('update', $widget));

        // And the dataset is not.
        $this->assertFalse($this->intruder->can('query', $widget->dataset));
        $this->assertFalse($this->intruder->can('view', $widget->dataset));
    }

    /**
     * A board filter keyed by ANOTHER owner's dataset id reaches nothing: the
     * merge only consults the group matching the widget's own dataset.
     */
    public function test_a_board_filter_keyed_by_a_foreign_dataset_does_nothing(): void
    {
        $this->ownerBoard->forceFill([
            'filters' => [(string) $this->intruderDataset->id => [
                ['column' => 'c1', 'operator' => 'eq', 'value' => 'Лондон'],
            ]],
        ])->save();

        $widget = BiWidget::factory()->create([
            'user_id' => $this->owner->id,
            'bi_dashboard_id' => $this->ownerBoard->id,
            'bi_dataset_id' => $this->ownerDataset->id,
            'type' => WidgetType::Column,
            'spec' => [
                'dimensions' => [['column' => 'c1']],
                'measures' => [['aggregate' => 'sum', 'column' => 'c2']],
            ],
        ]);

        $rendered = app(WidgetRenderer::class)->render($widget, $this->ownerBoard->fresh());

        $this->assertFalse($rendered->failed());
        $this->assertSame(['Душанбе'], $rendered->result->column('d0'));
    }

    /**
     * Two boards, two owners, the identical question of identically-shaped
     * datasets. The cache must not let the second one see the first's numbers.
     */
    public function test_identical_boards_for_two_owners_never_share_cached_numbers(): void
    {
        $intruderBoard = BiDashboard::factory()->create(['user_id' => $this->intruder->id]);

        $spec = [
            'dimensions' => [['column' => 'c1']],
            'measures' => [['aggregate' => 'sum', 'column' => 'c2']],
        ];

        $ownerWidget = BiWidget::factory()->create([
            'user_id' => $this->owner->id,
            'bi_dashboard_id' => $this->ownerBoard->id,
            'bi_dataset_id' => $this->ownerDataset->id,
            'type' => WidgetType::Column, 'spec' => $spec,
        ]);

        $intruderWidget = BiWidget::factory()->create([
            'user_id' => $this->intruder->id,
            'bi_dashboard_id' => $intruderBoard->id,
            'bi_dataset_id' => $this->intruderDataset->id,
            'type' => WidgetType::Column, 'spec' => $spec,
        ]);

        $first = app(WidgetRenderer::class)->render($ownerWidget, $this->ownerBoard);
        $second = app(WidgetRenderer::class)->render($intruderWidget, $intruderBoard);

        $this->assertFalse($second->cached(), 'The second owner must not hit the first one\'s cache entry.');
        $this->assertSame(['Душанбе'], $first->result->column('d0'));
        $this->assertSame(['Лондон'], $second->result->column('d0'));
    }

    public function test_the_board_switcher_lists_only_the_signed_in_account(): void
    {
        BiDashboard::factory()->create(['user_id' => $this->intruder->id, 'name' => 'Чужая доска']);

        $this->actingAs($this->owner)
            ->get(route('bi.dashboards.show', $this->ownerBoard))
            ->assertOk()
            ->assertDontSee('Чужая доска');
    }

    /**
     * The dataset picker in the editor offers only this account's uploads — so
     * a foreign id is never even presented, let alone accepted.
     */
    public function test_the_dataset_picker_offers_only_this_accounts_datasets(): void
    {
        $response = $this->actingAs($this->owner)->get(route('bi.dashboards.show', $this->ownerBoard));

        $response->assertOk();

        $datasets = $response->viewData('datasets');

        $this->assertTrue($datasets->contains('id', $this->ownerDataset->id));
        $this->assertFalse($datasets->contains('id', $this->intruderDataset->id));
    }

    public function test_owned_by_scopes_hide_the_other_account(): void
    {
        $intruderBoard = BiDashboard::factory()->create(['user_id' => $this->intruder->id]);

        $visible = BiDashboard::ownedBy($this->owner)->pluck('id')->all();

        $this->assertContains($this->ownerBoard->getKey(), $visible);
        $this->assertNotContains($intruderBoard->getKey(), $visible);
    }

    public function test_global_filters_are_validated_against_owners_dataset_before_save(): void
    {
        $this->actingAs($this->owner)->patchJson(route('bi.dashboards.update', $this->ownerBoard), [
            'name' => $this->ownerBoard->name,
            'filters' => [(string) $this->ownerDataset->id => [['column' => 'c1', 'operator' => 'eq', 'value' => 'Душанбе']]],
        ])->assertOk()->assertJsonPath('filters.'.$this->ownerDataset->id.'.0.value', 'Душанбе');

        $before = $this->ownerBoard->fresh()->filters;
        $this->actingAs($this->owner)->patchJson(route('bi.dashboards.update', $this->ownerBoard), [
            'name' => $this->ownerBoard->name,
            'filters' => [(string) $this->intruderDataset->id => [['column' => 'c0', 'operator' => 'eq', 'value' => 'x']]],
        ])->assertUnprocessable()->assertJsonValidationErrors('filters');
        $this->assertSame($before, $this->ownerBoard->fresh()->filters);
    }

    /**
     * A board's query count must not grow with the number of cards: forty
     * widgets lazy-loading their dataset one at a time is the N+1 this module's
     * perf tests exist to catch.
     */
    public function test_a_board_does_not_run_a_query_per_widget(): void
    {
        BiWidget::factory()->count(8)->create([
            'user_id' => $this->owner->id,
            'bi_dashboard_id' => $this->ownerBoard->id,
            'bi_dataset_id' => $this->ownerDataset->id,
            'type' => WidgetType::Column,
            'spec' => [
                'dimensions' => [['column' => 'c1']],
                'measures' => [['aggregate' => 'sum', 'column' => 'c2']],
            ],
        ]);

        $this->actingAs($this->owner);

        DB::enableQueryLog();
        $response = $this->get(route('bi.dashboards.show', $this->ownerBoard));
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertOk();

        // Session, user, board, widgets, datasets, columns, the picker, one
        // tenant-scoped chat-history query — plus
        // ONE analytical query, because all eight cards ask the identical
        // question and therefore share a cache entry. Nowhere near 8+.
        //
        // 17 -> 18 (Stage 3a): the dashboard shell's new team switcher runs
        // one flat, page-independent query (a single join over team_user,
        // so it never scales with anything this test actually guards
        // against) to list the signed-in user's teams. Every page behind
        // this layout picked up the same +1 — see Perf\QueryBudgetTest's
        // own generous, unbumped budgets, which absorbed it without
        // needing a change.
        $this->assertLessThanOrEqual(18, $count, "The board ran {$count} queries — possible N+1.");
    }
}
