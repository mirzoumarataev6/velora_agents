<?php

namespace Tests\Feature\Settings;

use App\Models\Agent;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\KnowledgeSource;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Role;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Permissions\PermissionCatalog;
use App\Services\Teams\CreatesTeam;
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
        $personalTeamId = $this->user->current_team_id;

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
        $this->assertDatabaseMissing('teams', ['id' => $personalTeamId]);
    }

    public function test_an_explicit_team_owner_cannot_delete_a_team_and_its_shared_data(): void
    {
        $team = app(CreatesTeam::class)->create($this->user, 'Sales');
        $agent = Agent::factory()->for($this->user)->create();
        $customer = Customer::factory()->for($this->user)->create();

        $this->actingAs($this->user)
            ->delete('/settings/account', ['confirmation' => 'owner@example.com'])
            ->assertSessionHasErrors('confirmation', errorBag: 'account');

        $this->assertAuthenticatedAs($this->user);
        $this->assertDatabaseHas('users', ['id' => $this->user->id]);
        $this->assertDatabaseHas('teams', ['id' => $team->id, 'is_personal' => false]);
        $this->assertDatabaseHas('agents', ['id' => $agent->id]);
        $this->assertDatabaseHas('customers', ['id' => $customer->id]);
    }

    public function test_a_former_member_cannot_delete_attributed_rows_left_in_an_explicit_team(): void
    {
        $owner = User::factory()->create();
        $team = app(CreatesTeam::class)->create($owner, 'Sales');
        $role = Role::where('team_id', $team->id)->where('slug', PermissionCatalog::OPERATOR)->sole();
        $membership = TeamMembership::create([
            'team_id' => $team->id,
            'user_id' => $this->user->id,
            'role_id' => $role->id,
            'joined_at' => now(),
        ]);
        $customer = Customer::factory()->for($this->user)->create(['team_id' => $team->id]);
        $membership->delete();

        $this->actingAs($this->user)
            ->delete('/settings/account', ['confirmation' => 'owner@example.com'])
            ->assertSessionHasErrors('confirmation', errorBag: 'account');

        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'team_id' => $team->id]);
        $this->assertDatabaseHas('users', ['id' => $this->user->id]);
    }

    public function test_a_member_without_shared_attribution_can_delete_only_their_own_account(): void
    {
        $owner = User::factory()->create();
        $team = app(CreatesTeam::class)->create($owner, 'Sales');
        $agent = Agent::factory()->for($owner)->create();
        $role = Role::where('team_id', $team->id)->where('slug', PermissionCatalog::VIEWER)->sole();
        TeamMembership::create([
            'team_id' => $team->id,
            'user_id' => $this->user->id,
            'role_id' => $role->id,
            'joined_at' => now(),
        ]);

        $this->actingAs($this->user)
            ->delete('/settings/account', ['confirmation' => 'owner@example.com'])
            ->assertRedirect('/');

        $this->assertDatabaseMissing('users', ['id' => $this->user->id]);
        $this->assertDatabaseHas('teams', ['id' => $team->id]);
        $this->assertDatabaseHas('agents', ['id' => $agent->id]);
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
