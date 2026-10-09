<?php

namespace Tests\Feature\Crm;

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_list_shows_only_this_owners_tasks(): void
    {
        $owner = User::factory()->create();
        Task::factory()->for($owner)->create(['title' => 'Перезвонить Азизу']);
        Task::factory()->create(['title' => 'Чужая задача']);

        $this->actingAs($owner)->get(route('crm.tasks.index'))
            ->assertOk()
            ->assertSee('Перезвонить Азизу')
            ->assertDontSee('Чужая задача');
    }

    public function test_task_page_exposes_the_priority_color_preview_and_animated_completion_state(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)->get(route('crm.tasks.index'))
            ->assertOk()
            ->assertSee("priorityOptionClass('urgent')", false)
            ->assertSee('crm-task-title-text--done', false)
            ->assertSee('crm-task-title-text', false)
            ->assertSee(':aria-busy="task.busy === true"', false)
            ->assertDontSee(':disabled="task.busy', false)
            ->assertSee(__('crm.tasks.priorities.urgent'));
    }

    public function test_the_filters_slice_the_list(): void
    {
        $owner = User::factory()->create();
        Task::factory()->for($owner)->overdue()->create(['title' => 'Просроченная']);
        Task::factory()->for($owner)->create(['title' => 'Завтрашняя', 'due_at' => now()->addDays(3)]);
        Task::factory()->for($owner)->done()->create(['title' => 'Сделанная']);

        $this->actingAs($owner)->get(route('crm.tasks.index', ['filter' => 'overdue']))
            ->assertSee('Просроченная')->assertDontSee('Завтрашняя');

        $this->actingAs($owner)->get(route('crm.tasks.index', ['filter' => 'done']))
            ->assertSee('Сделанная')->assertDontSee('Просроченная');

        // "Open" is everything not yet ticked off, whatever its date.
        $this->actingAs($owner)->get(route('crm.tasks.index', ['filter' => 'open']))
            ->assertSee('Просроченная')->assertSee('Завтрашняя')->assertDontSee('Сделанная');
    }

    /** Overdue work is still today's work, so "due today" includes it. */
    public function test_due_today_includes_what_is_already_late(): void
    {
        // Freeze in the middle of the day. This assertion is about the query's
        // day boundary, not which side of midnight the suite happens to run on.
        $this->travelTo(now()->startOfDay()->addHours(12));

        $owner = User::factory()->create();
        Task::factory()->for($owner)->overdue()->create();
        Task::factory()->for($owner)->dueToday()->create();
        Task::factory()->for($owner)->create(['due_at' => now()->addWeek()]);

        $this->assertSame(2, Task::ownedBy($owner)->dueToday()->count());
    }

    /** A task with no date can never be late — that is why the date is optional. */
    public function test_an_undated_task_is_never_overdue(): void
    {
        $owner = User::factory()->create();
        $task = Task::factory()->for($owner)->undated()->create();

        $this->assertFalse($task->isOverdue());
        $this->assertSame(0, Task::ownedBy($owner)->overdue()->count());
    }

    public function test_a_task_can_be_attached_to_a_customer_and_a_deal(): void
    {
        $owner = User::factory()->create();
        $customer = Customer::factory()->for($owner)->create();
        $deal = Deal::factory()->for($owner)->for($customer)->create();

        $this->actingAs($owner)->post(route('crm.tasks.store'), [
            'title' => 'Отправить счёт',
            'due_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'customer_id' => $customer->id,
            'deal_id' => $deal->id,
        ])->assertRedirect();

        $task = Task::sole();

        $this->assertSame($customer->id, $task->customer_id);
        $this->assertSame($deal->id, $task->deal_id);
        $this->assertSame($owner->id, $task->user_id);
    }

    public function test_tasks_persist_priority_and_type_and_support_json_create_and_toggle(): void
    {
        $owner = User::factory()->create();

        $created = $this->actingAs($owner)->postJson(route('crm.tasks.store'), [
            'title' => 'Подтвердить доставку', 'priority' => 'urgent', 'type' => 'call',
        ])->assertCreated()
            ->assertJsonPath('task.priority', 'urgent')
            ->assertJsonPath('task.type', 'call');

        $task = Task::sole();
        $this->assertSame('urgent', $task->priority);
        $this->assertSame('call', $task->type);

        $this->patchJson(route('crm.tasks.update', $task), ['done' => true])
            ->assertOk()->assertJsonPath('done', true);
        $this->assertNotNull($task->fresh()->done_at);
    }

    public function test_task_can_create_a_customer_inline_and_link_it_atomically(): void
    {
        $owner = User::factory()->create();

        $response = $this->actingAs($owner)->postJson(route('crm.tasks.store'), [
            'title' => 'Уточнить размер заказа',
            'priority' => 'urgent',
            'type' => 'call',
            'new_customer_name' => 'Шахноза Каримова',
        ])->assertCreated()
            ->assertJsonPath('task.customer_name', 'Шахноза Каримова');

        $customer = Customer::sole();
        $task = Task::sole();

        $this->assertSame($owner->id, $customer->user_id);
        $this->assertSame($customer->id, $task->customer_id);
        $this->assertSame($owner->id, $task->user_id);
    }

    public function test_task_customer_picker_uses_search_instead_of_rendering_the_whole_customer_book(): void
    {
        $owner = User::factory()->create();
        Customer::factory()->for($owner)->create(['name' => 'Клиент из подсказки']);

        $this->actingAs($owner)->get(route('crm.tasks.index'))
            ->assertOk()
            ->assertSee(route('crm.customers.search'), false)
            ->assertSee('searchCustomers()', false)
            ->assertSee('chooseNewCustomer()', false)
            ->assertDontSee('id="task-customer"', false)
            ->assertSee('role="radiogroup"', false)
            ->assertSee('priorityDotClass(task.priority)', false)
            ->assertSee('bg-red-50', false);
    }

    public function test_task_customer_suggestions_remain_owner_scoped(): void
    {
        $owner = User::factory()->create();
        Customer::factory()->for($owner)->create(['name' => 'Мой Бобур']);
        Customer::factory()->create(['name' => 'Чужой Бобур']);

        $this->actingAs($owner)->getJson(route('crm.customers.search', ['q' => 'Бобур']))
            ->assertOk()
            ->assertJsonCount(1, 'customers')
            ->assertJsonPath('customers.0.name', 'Мой Бобур');
    }

    /** A task with neither is still a task. */
    public function test_a_task_needs_neither_a_customer_nor_a_date(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)->post(route('crm.tasks.store'), ['title' => 'Купить коробки'])
            ->assertRedirect();

        $task = Task::sole();

        $this->assertNull($task->customer_id);
        $this->assertNull($task->due_at);
    }

    public function test_a_task_rejects_another_owners_customer_instead_of_dropping_the_link(): void
    {
        $owner = User::factory()->create();
        $theirs = Customer::factory()->create();

        $this->actingAs($owner)->post(route('crm.tasks.store'), [
            'title' => 'Подозрительная задача',
            'customer_id' => $theirs->id,
        ])->assertSessionHasErrors('customer_id');

        $this->assertSame(0, Task::count());
    }

    public function test_a_task_rejects_a_deal_from_another_owner(): void
    {
        $owner = User::factory()->create();
        $foreignDeal = Deal::factory()->create();

        $this->actingAs($owner)->post(route('crm.tasks.store'), [
            'title' => 'Неверная связь', 'deal_id' => $foreignDeal->id,
        ])->assertSessionHasErrors('deal_id');

        $this->assertSame(0, Task::count());
    }

    public function test_a_task_rejects_a_deal_that_belongs_to_a_different_selected_customer(): void
    {
        $owner = User::factory()->create();
        $customer = Customer::factory()->for($owner)->create();
        $deal = Deal::factory()->for($owner)->for(Customer::factory()->for($owner))->create();

        $this->actingAs($owner)->post(route('crm.tasks.store'), [
            'title' => 'Неверная связь', 'customer_id' => $customer->id, 'deal_id' => $deal->id,
        ])->assertSessionHasErrors('deal_id');

        $this->assertSame(0, Task::count());
    }

    public function test_ticking_a_task_marks_it_done_and_unticking_reopens_it(): void
    {
        $owner = User::factory()->create();
        $task = Task::factory()->for($owner)->create();

        $this->actingAs($owner)->patch(route('crm.tasks.update', $task), ['done' => 1])->assertRedirect();
        $this->assertNotNull($task->fresh()->done_at);

        $this->actingAs($owner)->patch(route('crm.tasks.update', $task), ['done' => 0])->assertRedirect();
        $this->assertNull($task->fresh()->done_at);
    }

    /** When it was finished is a fact, not something a second tick rewrites. */
    public function test_re_ticking_a_done_task_keeps_its_original_completion_time(): void
    {
        $owner = User::factory()->create();
        $task = Task::factory()->for($owner)->create(['done_at' => now()->subDays(3)]);
        $original = $task->done_at;

        $this->actingAs($owner)->patch(route('crm.tasks.update', $task), ['done' => 1]);

        $this->assertTrue($original->equalTo($task->fresh()->done_at));
    }

    public function test_the_owner_can_rename_reschedule_and_delete_a_task(): void
    {
        $owner = User::factory()->create();
        $task = Task::factory()->for($owner)->create();

        $this->actingAs($owner)->patch(route('crm.tasks.update', $task), [
            'title' => 'Перезвонить в пятницу',
            'due_at' => now()->addDays(5)->format('Y-m-d\TH:i'),
        ])->assertRedirect();

        $this->assertSame('Перезвонить в пятницу', $task->fresh()->title);

        $this->actingAs($owner)->delete(route('crm.tasks.destroy', $task))->assertRedirect();
        $this->assertSame(0, Task::count());
    }

    public function test_another_owners_task_cannot_be_touched(): void
    {
        $task = Task::factory()->create(['title' => 'Не трогать']);
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->patch(route('crm.tasks.update', $task), ['done' => 1])->assertForbidden();
        $this->actingAs($stranger)->delete(route('crm.tasks.destroy', $task))->assertForbidden();

        $this->assertNull($task->fresh()->done_at);
    }

    /**
     * What is overdue and due today lives in the Задачи tab, via its own
     * filters — never on the deals list (a bug this once was: a "На
     * сегодня" widget used to sit there too, duplicating this) and never on
     * the dashboard, which is a report.
     */
    public function test_overdue_and_due_today_surface_only_in_the_tasks_tab(): void
    {
        $owner = User::factory()->create();
        Task::factory()->for($owner)->overdue()->create(['title' => 'Позвонить в банк']);
        Task::factory()->for($owner)->create(['title' => 'Задача на следующей неделе', 'due_at' => now()->addWeek()]);

        $this->actingAs($owner)->get(route('crm.tasks.index', ['filter' => 'overdue']))
            ->assertOk()
            ->assertSee('Позвонить в банк')
            ->assertDontSee('Задача на следующей неделе');

        $this->actingAs($owner)->get(route('crm.board'))->assertDontSee('Позвонить в банк');
        $this->actingAs($owner)->get(route('dashboard'))->assertDontSee('Позвонить в банк');
    }

    public function test_the_task_pages_require_authentication(): void
    {
        $this->get(route('crm.tasks.index'))->assertRedirect();
        $this->post(route('crm.tasks.store'), ['title' => 'x'])->assertRedirect();
    }
}
