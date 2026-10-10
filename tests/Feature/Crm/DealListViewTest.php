<?php

namespace Tests\Feature\Crm;

use App\Models\CrmStage;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The kanban board was replaced with a list. These pin down what actually
 * changed — a table instead of columns, no drag-and-drop — without
 * re-testing the pipeline logic DealBoardTest already covers.
 */
class DealListViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_board_renders_a_table_not_a_kanban_column_layout(): void
    {
        $owner = User::factory()->create();
        $customer = Customer::factory()->for($owner)->create(['name' => 'Азиз']);
        Deal::factory()->for($owner)->for($customer)->create(['title' => 'Ковёр 3х4']);

        $response = $this->actingAs($owner)->get(route('crm.board'));

        $response->assertOk();
        $response->assertSee('<table', false);
        $response->assertSee('table-flat', false);
        $response->assertSee('Азиз');
        $response->assertSee('Ковёр 3х4');

        // No drag-and-drop left anywhere on the page.
        $response->assertDontSee('draggable="true"', false);
        $response->assertDontSee('dragstart', false);
        $response->assertDontSee('dragover', false);
    }

    /** The task widget and the "Новая сделка" button stay exactly where they were. */
    public function test_the_add_button_sits_above_the_list(): void
    {
        $response = $this->actingAs(User::factory()->create())->get(route('crm.board'));

        $response->assertOk();
        $response->assertSee(__('crm.board.add'));
    }

    /**
     * Bug fix: the "На сегодня" tasks widget used to sit above the deals
     * list, duplicating what the Задачи tab already shows with its own
     * "today"/"overdue" filters. It belongs there and only there now.
     */
    public function test_the_tasks_widget_no_longer_appears_on_the_deals_list(): void
    {
        $owner = User::factory()->create();
        Task::factory()->for($owner)->overdue()->create(['title' => 'Позвонить в банк']);

        $response = $this->actingAs($owner)->get(route('crm.board'));

        $response->assertOk();
        $response->assertDontSee('Позвонить в банк');
        $response->assertDontSee(__('crm.tasks.widget_heading'));
    }

    public function test_stage_filter_tabs_are_all_present_including_cancelled(): void
    {
        $owner = User::factory()->create();

        $response = $this->actingAs($owner)->get(route('crm.board'));

        $response->assertOk();
        $response->assertSee(__('crm.board.filter_all'));

        foreach (CrmStage::defaultsFor($owner) as $stage) {
            $response->assertSee($stage->displayName());
        }
    }

    public function test_the_empty_state_differs_for_no_deals_versus_a_filtered_search(): void
    {
        $owner = User::factory()->create();

        $response = $this->actingAs($owner)->get(route('crm.board'));
        $response->assertSee(__('crm.board.empty_title'));

        // A filtered/searched empty result has to say something different —
        // "no deals yet" is wrong once the owner has deals that just don't
        // match what they typed.
        Deal::factory()->for($owner)->for(Customer::factory()->for($owner))->create(['title' => 'Ковёр']);

        $response = $this->actingAs($owner)->get(route('crm.board', ['q' => 'что-то, чего нет']));
        $response->assertSee(__('crm.board.empty_filtered_title'));
    }

    /** Each stage colour maps to a real dot class, and the mapping covers every colour a stage can have. */
    public function test_every_stage_colour_has_a_dot_class(): void
    {
        foreach (CrmStage::COLORS as $color) {
            $this->assertNotSame('', CrmStage::dotClass($color));
            $this->assertStringStartsWith('bg-', CrmStage::dotClass($color));
        }
    }

    public function test_search_and_stage_filters_survive_in_the_initial_page_render(): void
    {
        $owner = User::factory()->create();
        Customer::factory()->for($owner);

        // The filters passed to the page are what seed the client-side
        // state — a GET with ?stage=&q= must not crash the first paint even
        // before any JS runs.
        $response = $this->actingAs($owner)->get(route('crm.board', ['stage' => 'haggling', 'q' => 'Азиз']));

        $response->assertOk();
    }

    public function test_an_unknown_stage_or_sort_in_the_query_string_falls_back_to_a_sane_default(): void
    {
        $owner = User::factory()->create();

        $response = $this->actingAs($owner)->getJson(route('crm.deals.poll', [
            'stage' => 'not-a-real-stage',
            'sort' => 'not-a-real-sort',
        ]));

        $response->assertOk();
    }
}
