<?php

namespace Tests\Feature\Bi;

use App\Models\BiDashboard;
use App\Models\BiDataset;
use App\Models\BiWidget;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Boards: creating, renaming, the default, and deleting.
 *
 * The two behaviours worth more than a CRUD smoke test are the DEFAULT — which
 * has to stay unique without a partial unique index, because the suite runs on
 * SQLite — and what happens to the section when the default is deleted.
 */
class DashboardCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    public function test_the_section_redirects_to_the_boards(): void
    {
        $this->actingAs($this->user)->get('/bi')->assertRedirect('/bi/dashboards');
    }

    /**
     * An owner with no boards AND no data is sent to the upload, not offered a
     * create form they cannot usefully complete.
     */
    public function test_the_empty_state_starts_with_upload_or_connection(): void
    {
        $this->actingAs($this->user)
            ->get(route('bi.dashboards.index'))
            ->assertOk()
            ->assertSee(__('bi.analysis.empty_body'))
            ->assertSee(__('bi.data.upload'))
            ->assertSee(__('bi.data.connect_db'));

        BiDataset::factory()->create([
            'user_id' => $this->user->id,
            'status' => BiDataset::STATUS_READY,
        ]);

        $this->actingAs($this->user)
            ->get(route('bi.dashboards.index'))
            ->assertOk()
            ->assertSee(__('bi.analysis.empty_body'));
    }

    public function test_a_dashboard_can_be_created_and_opened(): void
    {
        $response = $this->actingAs($this->user)
            ->post(route('bi.dashboards.store'), ['name' => 'Продажи']);

        $dashboard = BiDashboard::firstOrFail();

        $response->assertRedirect(route('bi.dashboards.show', $dashboard));
        $this->assertSame($this->user->id, $dashboard->user_id);

        $this->actingAs($this->user)
            ->get(route('bi.dashboards.show', $dashboard))
            ->assertOk()
            ->assertSee('Продажи');
    }

    /**
     * The first board becomes the landing board without being asked — there is
     * nothing else for the section to open.
     */
    public function test_the_first_dashboard_becomes_the_default(): void
    {
        $this->actingAs($this->user)->post(route('bi.dashboards.store'), ['name' => 'Первый']);

        $this->assertTrue(BiDashboard::firstOrFail()->is_default);
    }

    public function test_making_one_default_demotes_the_previous_one(): void
    {
        $first = BiDashboard::factory()->default()->create(['user_id' => $this->user->id]);
        $second = BiDashboard::factory()->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)->patch(route('bi.dashboards.update', $second), [
            'name' => $second->name,
            'is_default' => true,
        ])->assertRedirect();

        $this->assertFalse($first->fresh()->is_default);
        $this->assertTrue($second->fresh()->is_default);
        $this->assertSame(1, BiDashboard::where('user_id', $this->user->id)->where('is_default', true)->count());
    }

    /**
     * Deleting the default promotes another board, so the section still has
     * somewhere to land rather than showing an empty state to an account that
     * still has boards.
     */
    public function test_deleting_the_default_promotes_another(): void
    {
        $first = BiDashboard::factory()->default()->create(['user_id' => $this->user->id]);
        $second = BiDashboard::factory()->create(['user_id' => $this->user->id, 'position' => 1]);

        $this->actingAs($this->user)
            ->delete(route('bi.dashboards.destroy', $first))
            ->assertRedirect(route('bi.dashboards.index'));

        $this->assertDatabaseMissing('bi_dashboards', ['id' => $first->id]);
        $this->assertTrue($second->fresh()->is_default);
    }

    public function test_deleting_a_dashboard_takes_its_widgets_with_it(): void
    {
        $dashboard = BiDashboard::factory()->create(['user_id' => $this->user->id]);
        $dataset = BiDataset::factory()->create(['user_id' => $this->user->id]);
        $widget = BiWidget::factory()->create([
            'user_id' => $this->user->id,
            'bi_dashboard_id' => $dashboard->id,
            'bi_dataset_id' => $dataset->id,
        ]);

        $this->actingAs($this->user)->delete(route('bi.dashboards.destroy', $dashboard));

        $this->assertDatabaseMissing('bi_widgets', ['id' => $widget->id]);
    }

    public function test_a_name_is_required(): void
    {
        $this->actingAs($this->user)
            ->post(route('bi.dashboards.store'), ['name' => ''])
            ->assertSessionHasErrors('name');
    }

    public function test_an_account_is_capped_at_its_dashboard_limit(): void
    {
        BiDashboard::factory()->count(BiDashboard::MAX_PER_OWNER)->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)
            ->post(route('bi.dashboards.store'), ['name' => 'Ещё один'])
            ->assertSessionHasErrors('name');

        $this->assertSame(BiDashboard::MAX_PER_OWNER, BiDashboard::count());
    }

    public function test_another_account_cannot_see_or_touch_a_dashboard(): void
    {
        $dashboard = BiDashboard::factory()->create(['user_id' => $this->user->id]);
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->get(route('bi.dashboards.show', $dashboard))->assertForbidden();
        $this->actingAs($intruder)->patch(route('bi.dashboards.update', $dashboard), ['name' => 'x'])->assertForbidden();
        $this->actingAs($intruder)->delete(route('bi.dashboards.destroy', $dashboard))->assertForbidden();

        $this->assertDatabaseHas('bi_dashboards', ['id' => $dashboard->id]);
    }

    public function test_the_index_lists_only_the_signed_in_account(): void
    {
        BiDashboard::factory()->create(['user_id' => $this->user->id, 'name' => 'Мой']);
        BiDashboard::factory()->create(['user_id' => User::factory()->create()->id, 'name' => 'Чужой']);

        $this->actingAs($this->user)
            ->get(route('bi.dashboards.index'))
            ->assertOk()
            ->assertSee('Мой')
            ->assertDontSee('Чужой');
    }
}
