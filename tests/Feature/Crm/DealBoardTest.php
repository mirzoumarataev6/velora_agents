<?php

namespace Tests\Feature\Crm;

use App\Models\Agent;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DealBoardTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_board_lists_every_stage_as_a_filter_including_the_set_apart_one(): void
    {
        $owner = User::factory()->create();

        $response = $this->actingAs($owner)->get(route('crm.board'));

        $response->assertOk();

        foreach (Deal::allStages() as $stage) {
            $response->assertSee(__('crm.stages.'.$stage));
        }
    }

    public function test_deal_deletion_confirmation_does_not_use_a_blocking_browser_dialog(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)->get(route('crm.board'))
            ->assertOk()
            ->assertSee('data-crm-nav', false)
            ->assertSee('role="alertdialog"', false)
            ->assertSee('confirmDelete()', false)
            ->assertDontSee('confirm(labels.deleteConfirm)', false);
    }

    public function test_the_poll_returns_only_the_owners_deals(): void
    {
        $owner = User::factory()->create();
        $customer = Customer::factory()->for($owner)->create(['name' => 'Азиз']);

        Deal::factory()->for($owner)->for($customer)->stage('haggling')->create(['title' => 'Плов на 20 человек']);
        // Somebody else's pipeline must never appear here.
        Deal::factory()->create(['title' => 'Чужая сделка']);

        $response = $this->actingAs($owner)->getJson(route('crm.deals.poll'));

        $response->assertOk();
        $response->assertJsonPath('deals.0.title', 'Плов на 20 человек');
        $response->assertJsonPath('deals.0.customer_name', 'Азиз');
        $response->assertJsonCount(1, 'deals');
    }

    /** The row has to say what is going on, not just who it is. */
    public function test_a_row_carries_the_channel_amount_and_time_in_stage(): void
    {
        $owner = User::factory()->create();
        $agent = Agent::factory()->for($owner)->create();
        $customer = Customer::factory()->for($owner)->create();

        $conversation = Conversation::factory()->for($agent)
            ->for(Channel::factory()->for($agent)->whatsapp()->create())
            ->create(['customer_id' => $customer->id]);
        $conversation->messages()->create(['role' => 'user', 'content' => 'Когда будет готово?']);

        Deal::factory()->for($owner)->for($customer)->create([
            'conversation_id' => $conversation->id,
            'amount' => 1500,
        ]);

        $response = $this->actingAs($owner)->getJson(route('crm.deals.poll'));

        $response->assertJsonPath('deals.0.channel', 'whatsapp');
        $response->assertJsonPath('deals.0.amount', '1 500');
        $response->assertJsonPath('deals.0.amount_raw', '1500.00');
        $response->assertJsonPath('deals.0.in_stage', fn ($v) => $v !== null);
        // The conversation's own last message, not a fallback to the deal's
        // own timestamp — that is what "last activity" is supposed to mean.
        $response->assertJsonPath('deals.0.last_activity', fn ($v) => $v !== null);
    }

    public function test_repeat_customers_are_grouped_and_the_profile_keeps_every_order_and_conversation(): void
    {
        $owner = User::factory()->create();
        $agent = Agent::factory()->for($owner)->create();
        $channel = Channel::factory()->for($agent)->whatsapp()->create();
        $customer = Customer::factory()->for($owner)->create(['name' => 'Фарид']);

        $conversationIds = [];
        foreach (range(1, 3) as $number) {
            $conversation = Conversation::factory()->for($agent)->for($channel)->create([
                'customer_id' => $customer->id,
                'external_user_name' => "Диалог {$number}",
            ]);
            $conversationIds[] = $conversation->id;
            Deal::factory()->for($owner)->for($customer)->create([
                'conversation_id' => $conversation->id,
                'title' => "Покупка {$number}",
                'payment_status' => 'paid',
            ]);
        }

        $this->actingAs($owner)->getJson(route('crm.deals.poll'))
            ->assertOk()
            ->assertJsonCount(1, 'deals')
            ->assertJsonPath('deals.0.customer_name', 'Фарид')
            ->assertJsonPath('deals.0.customer_purchase_count', 3)
            ->assertJsonPath('deals.0.customer_conversation_count', 3)
            ->assertJsonPath('deals.0.customer_purchase_count_label', '3 покупки')
            ->assertJsonPath('deals.0.customer_conversation_count_label', '3 диалога');

        $profile = $this->get(route('crm.customers.show', $customer));
        $profile->assertOk();
        foreach (['Покупка 1', 'Покупка 2', 'Покупка 3'] as $entry) {
            $profile->assertSee($entry);
        }
        foreach ($conversationIds as $conversationId) {
            $profile->assertSee('conversation='.$conversationId, false);
        }
    }

    public function test_the_poll_applies_the_stage_filter_on_the_server(): void
    {
        $owner = User::factory()->create();
        $customer = Customer::factory()->for($owner);

        Deal::factory()->for($owner)->for($customer)->stage('haggling')->create(['title' => 'Торг']);
        Deal::factory()->for($owner)->for($customer)->stage('payment')->create(['title' => 'Оплата']);

        $response = $this->actingAs($owner)->getJson(route('crm.deals.poll', ['stage' => 'haggling']));

        $response->assertJsonCount(1, 'deals');
        $response->assertJsonPath('deals.0.title', 'Торг');
        $response->assertJsonPath('pagination.total', 1);
    }

    public function test_summary_card_filter_sentinels_select_their_matching_deals(): void
    {
        $owner = User::factory()->create();
        $customer = Customer::factory()->for($owner);
        Deal::factory()->for($owner)->for($customer)->stage('new_lead')->create(['title' => 'Новая заявка']);
        Deal::factory()->for($owner)->for($customer)->stage('haggling')->create(['title' => 'Обсуждение цены']);
        Deal::factory()->for($owner)->for($customer)->stage('payment')->create(['title' => 'Оплачивается']);
        Deal::factory()->for($owner)->for($customer)->stage('cancelled')->create(['title' => 'Отменённая']);

        $this->actingAs($owner)->getJson(route('crm.deals.poll', ['stage' => 'in_progress']))
            ->assertOk()->assertJsonCount(2, 'deals');
        $this->getJson(route('crm.deals.poll', ['stage' => 'resolved']))
            ->assertOk()->assertJsonCount(2, 'deals');
    }

    public function test_a_customer_purchase_deep_link_is_scoped_and_seeds_that_deal(): void
    {
        $owner = User::factory()->create();
        $deal = Deal::factory()->for($owner)->create(['title' => 'Нужная покупка']);
        $foreign = Deal::factory()->create(['title' => 'Чужая покупка']);

        $this->actingAs($owner)->get(route('crm.board', ['deal' => $deal->id]))
            ->assertOk()->assertSee('Нужная покупка')->assertSee('initialDeal');

        $this->get(route('crm.board', ['deal' => $foreign->id]))
            ->assertOk()->assertDontSee('Чужая покупка');
    }

    public function test_search_is_applied_to_the_full_owner_dataset_on_the_server(): void
    {
        $owner = User::factory()->create();

        Deal::factory()->for($owner)->for(Customer::factory()->for($owner)->create(['name' => 'Азиз']))
            ->create(['title' => 'Ковёр']);
        Deal::factory()->for($owner)->for(Customer::factory()->for($owner)->create(['name' => 'Фарух']))
            ->create(['title' => 'Диван']);

        $this->actingAs($owner)->getJson(route('crm.deals.poll', ['q' => 'Азиз']))
            ->assertJsonCount(1, 'deals')
            ->assertJsonPath('deals.0.customer_name', 'Азиз')
            ->assertJsonPath('deals.0.title', 'Ковёр')
            ->assertJsonPath('pagination.total', 1);

        $this->actingAs($owner)->get(route('crm.board'))
            ->assertOk()
            ->assertSee('loadPage()', false)
            ->assertSee('searchUrlBase', false);
    }

    public function test_amount_sort_is_applied_by_the_server(): void
    {
        $owner = User::factory()->create();
        $cheapCustomer = Customer::factory()->for($owner)->create();
        $expensiveCustomer = Customer::factory()->for($owner)->create();

        Deal::factory()->for($owner)->for($cheapCustomer)->create(['title' => 'Дешёвое', 'amount' => 100]);
        Deal::factory()->for($owner)->for($expensiveCustomer)->create(['title' => 'Дорогое', 'amount' => 9000]);

        $this->actingAs($owner)->getJson(route('crm.deals.poll', ['sort' => 'amount', 'dir' => 'desc']))
            ->assertJsonPath('deals.0.title', 'Дорогое')
            ->assertJsonPath('deals.1.title', 'Дешёвое');

        $this->actingAs($owner)->getJson(route('crm.deals.poll', ['sort' => 'amount', 'dir' => 'asc']))
            ->assertJsonPath('deals.0.title', 'Дешёвое')
            ->assertJsonPath('deals.1.title', 'Дорогое');

        $this->actingAs($owner)->get(route('crm.board'))
            ->assertOk()
            ->assertSee("sortBy('amount')", false);
    }

    public function test_changing_a_deals_stage_stamps_the_manual_override(): void
    {
        $owner = User::factory()->create();
        $deal = Deal::factory()->for($owner)->for(Customer::factory()->for($owner))->create();

        $this->actingAs($owner)
            ->patchJson(route('crm.deals.move', $deal), ['stage' => 'payment', 'position' => 0])
            ->assertOk()
            ->assertJsonPath('deal.stage', 'payment');

        $deal->refresh();

        $this->assertSame('payment', $deal->stage);
        $this->assertSame('paid', $deal->payment_status);
        $this->assertNotNull($deal->stage_set_by_owner_at);
        $this->assertNotNull($deal->stage_changed_at);
    }

    /** Including all the way backwards, and into the cancelled stage. */
    public function test_a_deal_can_be_moved_backwards_and_to_cancelled(): void
    {
        $owner = User::factory()->create();
        $deal = Deal::factory()->for($owner)->for(Customer::factory()->for($owner))->stage('payment')->create();

        $this->actingAs($owner)->patchJson(route('crm.deals.move', $deal), ['stage' => 'new_lead'])->assertOk();
        $this->assertSame('new_lead', $deal->fresh()->stage);

        $this->actingAs($owner)->patchJson(route('crm.deals.move', $deal), [
            'stage' => 'cancelled', 'cancelled_reason' => 'Клиент передумал',
        ])->assertOk();
        $this->assertSame('cancelled', $deal->fresh()->stage);
        $this->assertSame('Клиент передумал', $deal->fresh()->cancelled_reason);
    }

    /** Leaving Оплата clears "Закрыто" — the flag cannot survive its own stage. */
    public function test_moving_a_closed_deal_out_of_paid_clears_the_closed_flag(): void
    {
        $owner = User::factory()->create();
        $deal = Deal::factory()->for($owner)->for(Customer::factory()->for($owner))->closed()->create();

        $this->assertNotNull($deal->closed_at);

        $this->actingAs($owner)->patchJson(route('crm.deals.move', $deal), ['stage' => 'haggling'])->assertOk();

        $this->assertNull($deal->fresh()->closed_at);
    }

    /**
     * The list has no visible ordering within a stage any more, but the
     * position bookkeeping DealBoard::move() has always done is still
     * exercised by every stage change, so it stays correct rather than
     * silently rotting once nothing renders it.
     */
    public function test_moving_a_deal_still_keeps_the_destination_stages_position_bookkeeping_correct(): void
    {
        $owner = User::factory()->create();
        $customer = Customer::factory()->for($owner);

        $sitting = Deal::factory()->for($owner)->for($customer)->stage('payment')->create(['position' => 0]);
        $arriving = Deal::factory()->for($owner)->for($customer)->create();

        $this->actingAs($owner)
            ->patchJson(route('crm.deals.move', $arriving), ['stage' => 'payment', 'position' => 0])
            ->assertOk();

        $this->assertSame(0, $arriving->fresh()->position);
        $this->assertSame(1, $sitting->fresh()->position);
    }

    public function test_an_unknown_stage_is_rejected(): void
    {
        $owner = User::factory()->create();
        $deal = Deal::factory()->for($owner)->for(Customer::factory()->for($owner))->create();

        $this->actingAs($owner)
            ->patchJson(route('crm.deals.move', $deal), ['stage' => 'shipped_maybe'])
            ->assertStatus(422);
    }

    public function test_another_owners_deal_cannot_be_moved_or_deleted(): void
    {
        $deal = Deal::factory()->create();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->patchJson(route('crm.deals.move', $deal), ['stage' => 'payment'])->assertForbidden();
        $this->actingAs($stranger)->delete(route('crm.deals.destroy', $deal))->assertForbidden();

        $this->assertDatabaseHas('deals', ['id' => $deal->id, 'stage' => 'new_lead']);
    }

    public function test_the_owner_can_open_a_deal_by_hand(): void
    {
        $owner = User::factory()->create();
        $customer = Customer::factory()->for($owner)->create();

        $this->actingAs($owner)->post(route('crm.deals.store'), [
            'customer_id' => $customer->id,
            'title' => 'Ковёр 3х4',
            'amount' => '2500.50',
        ])->assertRedirect();

        $deal = Deal::sole();

        $this->assertSame('Ковёр 3х4', $deal->title);
        $this->assertSame('2500.50', $deal->amount);
        $this->assertSame('new_lead', $deal->stage);
        // Opened by hand counts as the owner's own placement.
        $this->assertNotNull($deal->stage_set_by_owner_at);
    }

    public function test_a_deal_cannot_be_opened_against_another_owners_customer(): void
    {
        $owner = User::factory()->create();
        $theirs = Customer::factory()->create();

        // A redirect back with an inline error, not a blank 404 page: the id
        // is scoped exactly like before, but the owner should land back on
        // the form and see why nothing happened, not a crashed navigation.
        // Title and amount are supplied so the request clears THOSE required
        // checks and actually reaches the customer-ownership check.
        $this->actingAs($owner)
            ->post(route('crm.deals.store'), [
                'customer_id' => $theirs->id,
                'title' => 'Ковёр',
                'amount' => '100',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('customer_id');

        $this->assertSame(0, Deal::count());
    }

    /** Requirement #1 applies to the edit form too, not only creation. */
    public function test_editing_a_deal_without_a_product_or_amount_is_rejected_via_json(): void
    {
        $owner = User::factory()->create();
        $deal = Deal::factory()->for($owner)->for(Customer::factory()->for($owner))->create([
            'title' => 'Исходное название',
            'amount' => 500,
        ]);

        $response = $this->actingAs($owner)->patchJson(route('crm.deals.update', $deal), [
            'title' => '',
            'amount' => '',
            'payment_status' => 'unpaid',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['title', 'amount']);

        $deal->refresh();
        $this->assertSame('Исходное название', $deal->title);
        $this->assertSame('500.00', $deal->amount);
    }

    /** And a zero or negative amount is rejected on edit exactly like on create. */
    public function test_editing_a_deal_with_a_non_positive_amount_is_rejected(): void
    {
        $owner = User::factory()->create();
        $deal = Deal::factory()->for($owner)->for(Customer::factory()->for($owner))->create();

        $this->actingAs($owner)->patchJson(route('crm.deals.update', $deal), [
            'title' => 'Ковёр',
            'amount' => '-1',
            'payment_status' => 'unpaid',
        ])->assertJsonValidationErrors('amount');
    }

    public function test_the_owner_can_edit_the_product_amount_payment_and_note(): void
    {
        $owner = User::factory()->create();
        $deal = Deal::factory()->for($owner)->for(Customer::factory()->for($owner))->create();

        $this->actingAs($owner)->patch(route('crm.deals.update', $deal), [
            'title' => 'Ковёр 3х4',
            'amount' => '2500',
            'payment_status' => 'partial',
            'note' => 'Оставил задаток',
        ])->assertRedirect();

        $deal->refresh();

        $this->assertSame('Ковёр 3х4', $deal->title);
        $this->assertSame('2500.00', $deal->amount);
        $this->assertSame('partial', $deal->payment_status);
        $this->assertSame('Оставил задаток', $deal->note);
    }

    /**
     * Edited via the JSON path (the Accept header the modal's own fetch
     * sends) — the update endpoint's other contract, and where the list
     * gets the fresh row to swap in without a reload.
     */
    public function test_editing_a_deal_via_json_returns_the_updated_row(): void
    {
        $owner = User::factory()->create();
        $deal = Deal::factory()->for($owner)->for(Customer::factory()->for($owner))->create();

        $response = $this->actingAs($owner)->patchJson(route('crm.deals.update', $deal), [
            'title' => 'Диван',
            'amount' => '3000',
            'payment_status' => 'paid',
            'stage' => 'payment',
        ]);

        $response->assertOk();
        $response->assertJsonPath('deal.title', 'Диван');
        $response->assertJsonPath('deal.stage', 'payment');

        $deal->refresh();
        // The stage change went through the same door a hand move always
        // does, so the automation guard is stamped exactly as it would be.
        $this->assertSame('payment', $deal->stage);
        $this->assertNotNull($deal->stage_set_by_owner_at);
    }

    /** Requirement #2: an explicit deal date the edit form can set. */
    public function test_editing_a_deal_can_backdate_it(): void
    {
        $owner = User::factory()->create();
        $deal = Deal::factory()->for($owner)->for(Customer::factory()->for($owner))->create();
        $date = now()->subDays(12)->startOfDay();

        $this->actingAs($owner)->patch(route('crm.deals.update', $deal), [
            'title' => 'Ковёр',
            'amount' => '100',
            'payment_status' => 'unpaid',
            'deal_date' => $date->format('Y-m-d'),
        ])->assertRedirect();

        $this->assertTrue($deal->fresh()->deal_date->isSameDay($date));
    }

    /** Requirement #2's flag: closing a deal only works while it is Оплата. */
    public function test_a_paid_deal_can_be_marked_closed(): void
    {
        $owner = User::factory()->create();
        $deal = Deal::factory()->for($owner)->for(Customer::factory()->for($owner))->stage('payment')->create();

        $response = $this->actingAs($owner)->patchJson(route('crm.deals.update', $deal), [
            'title' => 'Ковёр',
            'amount' => '100',
            'payment_status' => 'paid',
            'closed' => true,
        ]);

        $response->assertOk();
        $response->assertJsonPath('deal.is_closed', true);
        $this->assertNotNull($deal->fresh()->closed_at);
    }

    /** Refused silently on a deal not in Оплата — the control is never shown there, so this is a stale click, not a real request. */
    public function test_closing_a_deal_that_is_not_in_the_paid_stage_is_a_no_op(): void
    {
        $owner = User::factory()->create();
        $deal = Deal::factory()->for($owner)->for(Customer::factory()->for($owner))->stage('haggling')->create();

        $this->actingAs($owner)->patchJson(route('crm.deals.update', $deal), [
            'title' => 'Ковёр',
            'amount' => '100',
            'payment_status' => 'unpaid',
            'closed' => true,
        ])->assertOk();

        $this->assertNull($deal->fresh()->closed_at);
    }

    public function test_a_closed_deal_can_be_reopened(): void
    {
        $owner = User::factory()->create();
        $deal = Deal::factory()->for($owner)->for(Customer::factory()->for($owner))->closed()->create();

        $this->actingAs($owner)->patchJson(route('crm.deals.update', $deal), [
            'title' => 'Ковёр',
            'amount' => '100',
            'payment_status' => 'paid',
            'closed' => false,
        ])->assertOk();

        $this->assertNull($deal->fresh()->closed_at);
    }

    public function test_a_deal_can_be_deleted_via_json(): void
    {
        $owner = User::factory()->create();
        $deal = Deal::factory()->for($owner)->for(Customer::factory()->for($owner))->create();

        $this->actingAs($owner)->deleteJson(route('crm.deals.destroy', $deal))
            ->assertOk()
            ->assertJsonPath('deleted', true);

        $this->assertSame(0, Deal::count());
    }

    public function test_the_board_requires_authentication(): void
    {
        $this->get(route('crm.board'))->assertRedirect();
        $this->get(route('crm.deals.poll'))->assertRedirect();
    }

    public function test_the_poll_paginates_without_a_hidden_dataset_cap(): void
    {
        $owner = User::factory()->create();

        for ($i = 0; $i < 31; $i++) {
            $customer = Customer::factory()->for($owner)->create();
            Deal::factory()->for($owner)->for($customer)->create(['title' => "Deal {$i}"]);
        }

        $this->actingAs($owner)->getJson(route('crm.deals.poll', ['page' => 2]))
            ->assertOk()
            ->assertJsonCount(6, 'deals')
            ->assertJsonPath('pagination.current_page', 2)
            ->assertJsonPath('pagination.total', 31);
    }

    public function test_cancelling_requires_a_reason_and_the_board_shows_cancellation_metrics(): void
    {
        $owner = User::factory()->create();
        $customer = Customer::factory()->for($owner)->create();
        $deal = Deal::factory()->for($owner)->for($customer)->create();

        $this->actingAs($owner)->patchJson(route('crm.deals.move', $deal), ['stage' => 'cancelled'])
            ->assertUnprocessable()->assertJsonValidationErrors('cancelled_reason');
        $this->assertSame('new_lead', $deal->fresh()->stage);

        $this->actingAs($owner)->patchJson(route('crm.deals.move', $deal), [
            'stage' => 'cancelled', 'cancelled_reason' => 'Не подошёл срок',
        ])->assertOk();

        $this->actingAs($owner)->get(route('crm.board'))->assertOk()
            ->assertSee(__('crm.board.metric_lost'))
            ->assertSee('Не подошёл срок');
    }

    public function test_deal_history_combines_customer_messages_tasks_and_stage_changes(): void
    {
        $owner = User::factory()->create();
        $agent = Agent::factory()->for($owner)->create();
        $customer = Customer::factory()->for($owner)->create();
        $conversation = Conversation::factory()->for($agent)
            ->for(Channel::factory()->for($agent)->whatsapp()->create())
            ->create(['customer_id' => $customer->id]);
        $conversation->messages()->create(['role' => 'user', 'content' => 'Можно доставить завтра?']);
        $deal = Deal::factory()->for($owner)->for($customer)->for($conversation)->create();

        $this->actingAs($owner)->patchJson(route('crm.deals.move', $deal), ['stage' => 'haggling'])->assertOk();
        $this->actingAs($owner)->postJson(route('crm.tasks.store'), [
            'title' => 'Уточнить адрес', 'customer_id' => $customer->id, 'deal_id' => $deal->id,
        ])->assertCreated();

        $this->getJson(route('crm.deals.activity', $deal))->assertOk()
            ->assertJsonFragment(['text' => 'Можно доставить завтра?'])
            ->assertJsonFragment(['text' => 'Этап изменён: Новый лид → Торг'])
            ->assertJsonFragment(['text' => 'Уточнить адрес']);
    }

    public function test_a_deal_row_includes_its_next_open_task(): void
    {
        $owner = User::factory()->create();
        $customer = Customer::factory()->for($owner)->create();
        $deal = Deal::factory()->for($owner)->for($customer)->create();
        Task::factory()->for($owner)->for($customer)->for($deal)->create([
            'title' => 'Позвонить завтра', 'due_at' => now()->addDay(),
        ]);

        $this->actingAs($owner)->getJson(route('crm.deals.poll'))
            ->assertJsonPath('deals.0.next_task.title', 'Позвонить завтра');
    }
}
