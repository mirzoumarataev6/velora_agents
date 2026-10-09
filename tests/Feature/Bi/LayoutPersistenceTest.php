<?php

namespace Tests\Feature\Bi;

use App\Models\BiDashboard;
use App\Models\BiDataset;
use App\Models\BiWidget;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Persisting a drag.
 *
 * The layout endpoint is the only bulk write in the module and the only one
 * driven by a JS library's idea of where things are, so its three properties
 * are each pinned here: it is scoped to ONE board, it clamps, and it skips rows
 * that did not actually move.
 */
class LayoutPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private BiDashboard $dashboard;

    private BiDataset $dataset;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->dashboard = BiDashboard::factory()->create(['user_id' => $this->user->id]);
        $this->dataset = BiDataset::factory()->create(['user_id' => $this->user->id]);
    }

    private function widget(array $slot = []): BiWidget
    {
        return BiWidget::factory()->create(array_merge([
            'user_id' => $this->user->id,
            'bi_dashboard_id' => $this->dashboard->id,
            'bi_dataset_id' => $this->dataset->id,
        ], $slot));
    }

    public function test_a_drag_is_persisted(): void
    {
        $a = $this->widget(['grid_x' => 0, 'grid_y' => 0]);
        $b = $this->widget(['grid_x' => 6, 'grid_y' => 0]);

        $this->actingAs($this->user)
            ->putJson(route('bi.dashboards.layout', $this->dashboard), [
                'layout' => [
                    ['id' => $a->id, 'x' => 6, 'y' => 4, 'w' => 6, 'h' => 4],
                    ['id' => $b->id, 'x' => 0, 'y' => 0, 'w' => 12, 'h' => 6],
                ],
            ])
            ->assertOk()
            ->assertJson(['moved' => 2]);

        $this->assertSame([6, 4, 6, 4], array_values($a->fresh()->only(['grid_x', 'grid_y', 'grid_w', 'grid_h'])));
        $this->assertSame([0, 0, 12, 6], array_values($b->fresh()->only(['grid_x', 'grid_y', 'grid_w', 'grid_h'])));
    }

    /**
     * Gridstack reports the WHOLE board on every change, so most of a payload
     * is rows that did not move. Writing them would turn one gesture into
     * thirty pointless UPDATEs.
     */
    public function test_unchanged_widgets_are_not_rewritten(): void
    {
        $a = $this->widget(['grid_x' => 0, 'grid_y' => 0, 'grid_w' => 6, 'grid_h' => 4]);
        $b = $this->widget(['grid_x' => 6, 'grid_y' => 0, 'grid_w' => 6, 'grid_h' => 4]);

        $response = $this->actingAs($this->user)
            ->putJson(route('bi.dashboards.layout', $this->dashboard), [
                'layout' => [
                    ['id' => $a->id, 'x' => 0, 'y' => 0, 'w' => 6, 'h' => 4],
                    ['id' => $b->id, 'x' => 0, 'y' => 4, 'w' => 6, 'h' => 4],
                ],
            ]);

        $response->assertOk()->assertJson(['moved' => 1]);
    }

    /**
     * Moving a card is not editing it: a board reorder must not make every
     * widget look freshly modified.
     */
    public function test_a_drag_does_not_bump_updated_at(): void
    {
        $widget = $this->widget(['grid_x' => 0, 'grid_y' => 0]);
        $before = $widget->updated_at;

        $this->travel(2)->minutes();

        $this->actingAs($this->user)->putJson(route('bi.dashboards.layout', $this->dashboard), [
            'layout' => [['id' => $widget->id, 'x' => 3, 'y' => 1, 'w' => 6, 'h' => 4]],
        ])->assertOk();

        $this->assertEquals(
            $before->toDateTimeString(),
            $widget->fresh()->updated_at->toDateTimeString()
        );
    }

    /**
     * THE SCOPE THAT MATTERS: dragging on board A must not rearrange board B,
     * even when both belong to the same owner. The ids are filtered through the
     * board's own relation rather than trusted.
     */
    public function test_a_payload_naming_another_board_moves_nothing(): void
    {
        $other = BiDashboard::factory()->create(['user_id' => $this->user->id]);
        $foreign = BiWidget::factory()->create([
            'user_id' => $this->user->id,
            'bi_dashboard_id' => $other->id,
            'bi_dataset_id' => $this->dataset->id,
            'grid_x' => 0, 'grid_y' => 0, 'grid_w' => 6, 'grid_h' => 4,
        ]);

        $this->actingAs($this->user)
            ->putJson(route('bi.dashboards.layout', $this->dashboard), [
                'layout' => [['id' => $foreign->id, 'x' => 9, 'y' => 9, 'w' => 3, 'h' => 9]],
            ])
            ->assertOk()
            ->assertJson(['moved' => 0]);

        $this->assertSame(0, $foreign->fresh()->grid_x);
        $this->assertSame(6, $foreign->fresh()->grid_w);
    }

    /** Out-of-range numbers are refused at the request layer. */
    public function test_out_of_range_coordinates_are_rejected(): void
    {
        $widget = $this->widget();

        foreach ([
            ['x' => 99, 'y' => 0, 'w' => 6, 'h' => 4],
            ['x' => 0, 'y' => 0, 'w' => 99, 'h' => 4],
            ['x' => 0, 'y' => 0, 'w' => 6, 'h' => 99],
            ['x' => -1, 'y' => 0, 'w' => 6, 'h' => 4],
        ] as $slot) {
            $this->actingAs($this->user)
                ->putJson(route('bi.dashboards.layout', $this->dashboard), [
                    'layout' => [array_merge(['id' => $widget->id], $slot)],
                ])
                ->assertStatus(422);
        }
    }

    /**
     * A row that is already wrong — written before a rule changed, or edited by
     * hand — is clamped on READ, so it cannot push a card off the board.
     */
    public function test_a_bad_row_is_clamped_when_it_is_read(): void
    {
        $widget = $this->widget();
        $widget->forceFill(['grid_x' => 11, 'grid_w' => 12, 'grid_h' => 400])->saveQuietly();

        $slot = $widget->fresh()->slot();

        $this->assertSame(12, $slot['w']);
        // x is clamped against the WIDTH, so a full-width card starts at 0.
        $this->assertSame(0, $slot['x']);
        $this->assertSame(BiWidget::MAX_H, $slot['h']);
    }

    public function test_another_account_cannot_rearrange_a_board(): void
    {
        $widget = $this->widget(['grid_x' => 0]);
        $intruder = User::factory()->create();

        $this->actingAs($intruder)
            ->putJson(route('bi.dashboards.layout', $this->dashboard), [
                'layout' => [['id' => $widget->id, 'x' => 9, 'y' => 9, 'w' => 3, 'h' => 3]],
            ])
            ->assertForbidden();

        $this->assertSame(0, $widget->fresh()->grid_x);
    }

    /**
     * A drag that raced a deletion in another tab still saves the cards that
     * remain — throwing away a visible rearrangement because one card is gone
     * would be the worse answer.
     */
    public function test_an_unknown_id_is_ignored_rather_than_failing_the_batch(): void
    {
        $widget = $this->widget(['grid_x' => 0]);

        $this->actingAs($this->user)
            ->putJson(route('bi.dashboards.layout', $this->dashboard), [
                'layout' => [
                    ['id' => $widget->id, 'x' => 3, 'y' => 0, 'w' => 6, 'h' => 4],
                    ['id' => 999_999, 'x' => 0, 'y' => 0, 'w' => 6, 'h' => 4],
                ],
            ])
            ->assertOk()
            ->assertJson(['moved' => 1]);

        $this->assertSame(3, $widget->fresh()->grid_x);
    }
}
