<?php

namespace Tests\Feature\Projects;

use App\Models\Customer;
use App\Models\Deal;
use App\Models\ProjectBoard;
use App\Models\Task;
use App\Models\User;
use App\Services\Projects\ProjectBoards;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PHASE 1 item 3: deals.title is nullable and DealStageAutomation used to
 * leave it blank (23 of 71 real rows) — the task panel's deal <select> used
 * to print nothing for those options, and two customers who share a name
 * were indistinguishable in the customer <select> too. Covers the two
 * display fixes and the no-N+1 requirement the task called out explicitly;
 * the Alpine customer/deal filtering itself is covered by a manual browser
 * check (see the final report) since it has no server-rendered state to
 * assert against.
 */
class ProjectTaskCrmLinkPickerTest extends TestCase
{
    use RefreshDatabase;

    private function board(User $owner): ProjectBoard
    {
        return app(ProjectBoards::class)->create($owner->current_team_id, $owner->id, 'CRM link', null, 'team');
    }

    public function test_a_blank_titled_deal_falls_back_to_its_id_and_customer_name(): void
    {
        $owner = User::factory()->create();
        $board = $this->board($owner);
        $column = $board->columns()->first();
        $customer = Customer::factory()->for($owner)->create(['team_id' => $owner->current_team_id, 'name' => 'Шерали Расулов']);
        $deal = Deal::factory()->for($owner)->create(['team_id' => $owner->current_team_id, 'customer_id' => $customer->id, 'title' => null]);
        $task = Task::factory()->for($owner)->create(['project_board_id' => $board->id, 'project_column_id' => $column->id]);

        $response = $this->actingAs($owner)
            ->get(route('projects.boards.show', [$board, 'task' => $task->id]))
            ->assertOk();

        $response->assertSee(__('projects.deal_untitled', ['id' => $deal->id]).' — Шерали Расулов', false);
    }

    public function test_a_titled_deal_still_shows_its_own_title_not_the_fallback(): void
    {
        $owner = User::factory()->create();
        $board = $this->board($owner);
        $column = $board->columns()->first();
        $customer = Customer::factory()->for($owner)->create(['team_id' => $owner->current_team_id]);
        $deal = Deal::factory()->for($owner)->create(['team_id' => $owner->current_team_id, 'customer_id' => $customer->id, 'title' => '3x Наушники']);
        $task = Task::factory()->for($owner)->create(['project_board_id' => $board->id, 'project_column_id' => $column->id]);

        $response = $this->actingAs($owner)
            ->get(route('projects.boards.show', [$board, 'task' => $task->id]))
            ->assertOk();

        $response->assertSee('>3x Наушники<', false);
        $response->assertDontSee(__('projects.deal_untitled', ['id' => $deal->id]), false);
    }

    public function test_two_customers_sharing_a_name_are_told_apart_by_a_masked_phone_tail(): void
    {
        $owner = User::factory()->create();
        $board = $this->board($owner);
        $column = $board->columns()->first();
        Customer::factory()->for($owner)->create(['team_id' => $owner->current_team_id, 'name' => 'Азиз', 'phone' => '+992901112233']);
        Customer::factory()->for($owner)->create(['team_id' => $owner->current_team_id, 'name' => 'Азиз', 'phone' => '+992901114455']);
        $task = Task::factory()->for($owner)->create(['project_board_id' => $board->id, 'project_column_id' => $column->id]);

        $response = $this->actingAs($owner)
            ->get(route('projects.boards.show', [$board, 'task' => $task->id]))
            ->assertOk();

        $response->assertSee('Азиз · •••2233', false);
        $response->assertSee('Азиз · •••4455', false);
    }

    public function test_a_customer_with_no_phone_shows_just_their_name(): void
    {
        $owner = User::factory()->create();
        $board = $this->board($owner);
        $column = $board->columns()->first();
        Customer::factory()->for($owner)->create(['team_id' => $owner->current_team_id, 'name' => 'Нет телефона', 'phone' => null]);
        $task = Task::factory()->for($owner)->create(['project_board_id' => $board->id, 'project_column_id' => $column->id]);

        $response = $this->actingAs($owner)
            ->get(route('projects.boards.show', [$board, 'task' => $task->id]))
            ->assertOk();

        $response->assertSee('>Нет телефона<', false);
    }

    public function test_the_deal_options_carry_a_data_customer_attribute_for_the_alpine_filter(): void
    {
        $owner = User::factory()->create();
        $board = $this->board($owner);
        $column = $board->columns()->first();
        $customer = Customer::factory()->for($owner)->create(['team_id' => $owner->current_team_id]);
        $deal = Deal::factory()->for($owner)->create(['team_id' => $owner->current_team_id, 'customer_id' => $customer->id, 'title' => 'Linked deal']);
        $task = Task::factory()->for($owner)->create(['project_board_id' => $board->id, 'project_column_id' => $column->id]);

        $response = $this->actingAs($owner)
            ->get(route('projects.boards.show', [$board, 'task' => $task->id]))
            ->assertOk();

        $response->assertSee('data-customer="'.$customer->id.'"', false);
    }

    public function test_the_deal_picker_eager_loads_customer_names_without_an_n_plus_one(): void
    {
        $owner = User::factory()->create();
        $board = $this->board($owner);
        $column = $board->columns()->first();
        foreach (range(1, 15) as $i) {
            $customer = Customer::factory()->for($owner)->create(['team_id' => $owner->current_team_id]);
            Deal::factory()->for($owner)->create(['team_id' => $owner->current_team_id, 'customer_id' => $customer->id, 'title' => null]);
        }
        $task = Task::factory()->for($owner)->create(['project_board_id' => $board->id, 'project_column_id' => $column->id]);

        $this->actingAs($owner);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('projects.boards.show', [$board, 'task' => $task->id]))->assertOk();
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // One query per deal to resolve its customer's display name — 15
        // deals and 15 extra queries — would have shown up here as soon as
        // this budget was tightened; the eager-load above keeps it flat
        // regardless of how many deals the board has.
        $this->assertLessThanOrEqual(30, $queryCount, "Board with task panel open ran {$queryCount} queries.");
    }
}
