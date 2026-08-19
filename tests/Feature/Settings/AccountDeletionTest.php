<?php

namespace Tests\Feature\Settings;

use App\Models\Agent;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\KnowledgeSource;
use App\Models\Lead;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Deleting an account has to take everything with it — a stranded conversation
 * or lead would outlive the person who owned it — and it must be impossible to
 * aim at anyone else's account.
 */
class AccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['email' => 'owner@example.com']);
    }

    /**
     * A full account: agent → channel → conversation → message, plus knowledge
     * and a captured lead.
     *
     * @return array<string, int>
     */
    protected function populate(User $user): array
    {
        $agent = Agent::factory()->for($user)->create();
        $channel = Channel::factory()->for($agent)->create();
        $conversation = Conversation::factory()->for($agent)->for($channel)->create();
        $message = Message::factory()->for($conversation)->create();
        $source = KnowledgeSource::factory()->for($agent)->create();
        $lead = Lead::factory()->for($agent)->create();

        return [
            'agent' => $agent->id,
            'channel' => $channel->id,
            'conversation' => $conversation->id,
            'message' => $message->id,
            'source' => $source->id,
            'lead' => $lead->id,
        ];
    }

    public function test_deleting_the_account_cascades_to_everything_it_owned(): void
    {
        $ids = $this->populate($this->user);

        $this->actingAs($this->user)
            ->delete('/settings/account', ['confirmation' => 'owner@example.com'])
            ->assertRedirect('/');

        $this->assertDatabaseMissing('users', ['id' => $this->user->id]);
        $this->assertDatabaseMissing('agents', ['id' => $ids['agent']]);
        $this->assertDatabaseMissing('channels', ['id' => $ids['channel']]);
        $this->assertDatabaseMissing('conversations', ['id' => $ids['conversation']]);
        $this->assertDatabaseMissing('messages', ['id' => $ids['message']]);
        $this->assertDatabaseMissing('knowledge_sources', ['id' => $ids['source']]);
        $this->assertDatabaseMissing('leads', ['id' => $ids['lead']]);
    }

    public function test_the_session_is_ended_by_the_deletion(): void
    {
        $this->actingAs($this->user)
            ->delete('/settings/account', ['confirmation' => 'owner@example.com']);

        $this->assertGuest();
    }

    /**
     * There is no id in the route, so a request can only ever name the account
     * it is signed in as — this proves nobody else's data moves.
     */
    public function test_deleting_your_account_leaves_everyone_elses_intact(): void
    {
        $other = User::factory()->create(['email' => 'other@example.com']);
        $otherIds = $this->populate($other);
        $this->populate($this->user);

        $this->actingAs($this->user)
            ->delete('/settings/account', ['confirmation' => 'owner@example.com']);

        $this->assertDatabaseHas('users', ['id' => $other->id]);
        $this->assertDatabaseHas('agents', ['id' => $otherIds['agent']]);
        $this->assertDatabaseHas('conversations', ['id' => $otherIds['conversation']]);
        $this->assertDatabaseHas('messages', ['id' => $otherIds['message']]);
        $this->assertDatabaseHas('leads', ['id' => $otherIds['lead']]);
    }

    /**
     * The modal is a courtesy; this check is the actual control.
     */
    public function test_a_wrong_confirmation_phrase_deletes_nothing(): void
    {
        $this->actingAs($this->user)
            ->delete('/settings/account', ['confirmation' => 'delete my account please'])
            ->assertSessionHasErrors('confirmation', errorBag: 'account');

        $this->assertDatabaseHas('users', ['id' => $this->user->id]);
        $this->assertAuthenticatedAs($this->user);
    }

    public function test_the_confirmation_is_required(): void
    {
        $this->actingAs($this->user)
            ->delete('/settings/account', [])
            ->assertSessionHasErrors('confirmation', errorBag: 'account');

        $this->assertDatabaseHas('users', ['id' => $this->user->id]);
    }

    public function test_the_literal_word_delete_also_confirms(): void
    {
        $this->actingAs($this->user)
            ->delete('/settings/account', ['confirmation' => 'DELETE'])
            ->assertRedirect('/');

        $this->assertDatabaseMissing('users', ['id' => $this->user->id]);
    }

    public function test_the_email_confirmation_ignores_case_and_padding(): void
    {
        $this->actingAs($this->user)
            ->delete('/settings/account', ['confirmation' => '  OWNER@example.COM  '])
            ->assertRedirect('/');

        $this->assertDatabaseMissing('users', ['id' => $this->user->id]);
    }

    /**
     * Lowercase "delete" is not the confirmation phrase — only the shouted form
     * is, so a half-typed word can't stand in for deliberate intent.
     */
    public function test_a_lowercase_delete_is_not_accepted(): void
    {
        $this->actingAs($this->user)
            ->delete('/settings/account', ['confirmation' => 'delete'])
            ->assertSessionHasErrors('confirmation', errorBag: 'account');

        $this->assertDatabaseHas('users', ['id' => $this->user->id]);
    }

    public function test_guests_cannot_delete_anything(): void
    {
        $this->delete('/settings/account', ['confirmation' => 'DELETE'])->assertRedirect();

        $this->assertDatabaseHas('users', ['id' => $this->user->id]);
    }
}
