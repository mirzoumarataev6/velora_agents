<?php

namespace Tests\Feature\Teams;

use App\Models\Agent;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\CrmNote;
use App\Models\CrmStage;
use App\Models\CrmWorkspace;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Role;
use App\Models\Task;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Permissions\PermissionCatalog;
use App\Services\Teams\CreatesPersonalTeam;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Stage 2d-4-4: cross-cutting proof that the six CRM `scopeOwnedBy()`s
 * (CrmWorkspace, CrmStage, Customer, Deal, Task, CrmNote — Stage 2d-4-1)
 * and their UI/background call sites (2d-4-2, 2d-4-3) now behave as one
 * system: team-scoped, switchable, and safe against a stale or foreign
 * `current_team_id`.
 */
class TeamScopedCrmTest extends TestCase
{
    use RefreshDatabase;

    private const MODELS = [
        CrmWorkspace::class,
        CrmStage::class,
        Customer::class,
        Deal::class,
        Task::class,
        CrmNote::class,
    ];

    /** @return array{0: User, 1: Team, 2: Team} a user who is a member of TWO teams */
    private function memberOfTwoTeams(): array
    {
        $owner = User::factory()->createQuietly();
        $teamA = app(CreatesPersonalTeam::class)->create($owner);
        $teamB = app(CreatesPersonalTeam::class)->create($owner);
        $owner->forceFill(['current_team_id' => $teamA->id])->save();

        return [$owner, $teamA, $teamB];
    }

    private function switchTo(User $user, Team $team): void
    {
        $user->forceFill(['current_team_id' => $team->id])->save();
        $user->flushPermissionCache();
    }

    /** One minimal, valid row for a given CRM model, stamped onto exactly $teamId. */
    private function createRow(string $model, int $userId, int $teamId): Model
    {
        return match ($model) {
            CrmWorkspace::class => CrmWorkspace::withoutTeamStamping(fn () => CrmWorkspace::forceCreate([
                'user_id' => $userId,
                'team_id' => $teamId,
                'name' => 'ws-'.Str::random(8),
                'color' => 'blue',
            ])),
            CrmStage::class => CrmStage::withoutTeamStamping(fn () => CrmStage::forceCreate([
                'user_id' => $userId,
                'team_id' => $teamId,
                'name' => 'stage-'.Str::random(8),
                'role' => null,
                'position' => null,
                'color' => 'blue',
                'is_system' => false,
            ])),
            Customer::class => Customer::withoutTeamStamping(fn () => Customer::forceCreate([
                'user_id' => $userId,
                'team_id' => $teamId,
                'name' => 'cust-'.Str::random(8),
                'status' => 'lead',
            ])),
            Deal::class => Deal::withoutTeamStamping(fn () => Deal::forceCreate([
                'user_id' => $userId,
                'team_id' => $teamId,
                'customer_id' => $this->createRow(Customer::class, $userId, $teamId)->id,
                'title' => 'deal-'.Str::random(8),
                'stage' => 'new_lead',
                'position' => 0,
                'stage_changed_at' => now(),
            ])),
            Task::class => Task::withoutTeamStamping(fn () => Task::forceCreate([
                'user_id' => $userId,
                'team_id' => $teamId,
                'title' => 'task-'.Str::random(8),
            ])),
            CrmNote::class => CrmNote::withoutTeamStamping(fn () => CrmNote::forceCreate([
                'user_id' => $userId,
                'team_id' => $teamId,
                'notable_type' => Customer::class,
                'notable_id' => $this->createRow(Customer::class, $userId, $teamId)->id,
                'kind' => 'note',
                'body' => 'a note',
                'happened_at' => now(),
            ])),
            default => throw new \InvalidArgumentException("No row builder for {$model}"),
        };
    }

    /** @return int how many rows of $model scopeOwnedBy($user) currently returns */
    private function visibleCount(string $model, User $user): int
    {
        return $model::ownedBy($user)->count();
    }

    public function test_each_model_scopes_to_the_active_team_and_switches_with_it(): void
    {
        [$owner, $teamA, $teamB] = $this->memberOfTwoTeams();

        foreach (self::MODELS as $model) {
            $this->createRow($model, $owner->id, $teamA->id);
            $this->createRow($model, $owner->id, $teamA->id);
            $this->createRow($model, $owner->id, $teamB->id);

            $this->switchTo($owner, $teamA);
            $this->assertSame(2, $this->visibleCount($model, $owner), "{$model}: active team A should see only A's 2 rows.");

            $this->switchTo($owner, $teamB);
            $this->assertSame(1, $this->visibleCount($model, $owner), "{$model}: active team B should see only B's 1 row.");
        }
    }

    public function test_each_model_returns_nothing_for_a_null_current_team(): void
    {
        [$owner, $teamA] = $this->memberOfTwoTeams();

        foreach (self::MODELS as $model) {
            $this->createRow($model, $owner->id, $teamA->id);
        }

        $owner->forceFill(['current_team_id' => null])->save();
        $owner->flushPermissionCache();

        foreach (self::MODELS as $model) {
            $this->assertSame(0, $this->visibleCount($model, $owner), "{$model}: a null current_team_id must return nothing, never fall back to user_id.");
        }
    }

    /**
     * The exact leak scopeOwnedBy() exists to stop: current_team_id points
     * at a real team row, but one this user does not belong to.
     */
    public function test_each_model_returns_nothing_for_a_team_the_user_is_not_a_member_of(): void
    {
        [$owner, $teamA] = $this->memberOfTwoTeams();
        $foreignTeam = Team::factory()->create();

        foreach (self::MODELS as $model) {
            $this->createRow($model, $owner->id, $teamA->id);
        }

        $owner->forceFill(['current_team_id' => $foreignTeam->id])->save();
        $owner->flushPermissionCache();

        foreach (self::MODELS as $model) {
            $this->assertSame(0, $this->visibleCount($model, $owner), "{$model}: a current_team_id the user is not a member of must return nothing, not team A's rows.");
        }
    }

    /**
     * Shared scopes and policies must agree: attribution to the creator
     * cannot hide a teammate's rows from an Operator with analytics.view.
     */
    public function test_a_second_user_sharing_the_team_sees_the_same_rows_through_the_scope(): void
    {
        $owner = User::factory()->createQuietly();
        $team = app(CreatesPersonalTeam::class)->create($owner);
        $owner->forceFill(['current_team_id' => $team->id])->save();

        $teammate = User::factory()->createQuietly();
        $teammateOwnTeam = app(CreatesPersonalTeam::class)->create($teammate);
        $teammate->forceFill(['current_team_id' => $teammateOwnTeam->id])->save();
        $memberRole = Role::where('team_id', $team->id)
            ->where('slug', PermissionCatalog::OPERATOR)
            ->firstOrFail();
        TeamMembership::create([
            'team_id' => $team->id,
            'user_id' => $teammate->id,
            'role_id' => $memberRole->id,
            'joined_at' => now(),
        ]);
        $this->switchTo($teammate, $team);

        foreach (self::MODELS as $model) {
            $row = $this->createRow($model, $owner->id, $team->id);

            $this->assertTrue(
                $model::ownedBy($teammate)->whereKey($row->id)->exists(),
                "{$model}: the scope should surface U's row to V, who shares the active team."
            );

            // The policy stage now grants the Operator's analytics.view
            // permission on teammates' CRM rows, matching the shared scope.
            $this->assertTrue(
                Gate::forUser($teammate)->allows('view', $row),
                "{$model}: analytics.view should grant access to the shared team's row."
            );
        }
    }

    public function test_dedup_resolves_a_teammates_thread_into_the_existing_customer(): void
    {
        $owner = User::factory()->createQuietly();
        $team = app(CreatesPersonalTeam::class)->create($owner);
        $owner->forceFill(['current_team_id' => $team->id])->save();

        $agent = Agent::factory()->for($owner)->create();
        $channel = Channel::factory()->for($agent)->whatsapp()->create();
        Conversation::factory()->for($agent)->for($channel)->create(['external_user_id' => '992900112233']);

        $existing = Customer::sole();

        $teammate = User::factory()->createQuietly();
        $teammateOwnTeam = app(CreatesPersonalTeam::class)->create($teammate);
        $teammate->forceFill(['current_team_id' => $teammateOwnTeam->id])->save();
        $teammateAgent = Agent::factory()->for($teammate)->create();
        $teammateAgent->forceFill(['team_id' => $team->id])->save();
        $teammateChannel = Channel::factory()->for($teammateAgent)->whatsapp()->create();

        $secondConversation = Conversation::factory()->for($teammateAgent)->for($teammateChannel)->create([
            'external_user_id' => '992900112233',
        ]);

        $this->assertSame(1, Customer::count(), 'A teammate\'s thread with the same number opened a second customer instead of resolving to V\'s agent in team A.');
        $this->assertSame($existing->id, $secondConversation->fresh()->customer_id);
    }

    public function test_attaching_a_deal_to_a_workspace_from_another_team_is_rejected_by_validation(): void
    {
        $owner = User::factory()->create();
        $customer = Customer::forceCreate([
            'user_id' => $owner->id,
            'crm_workspace_id' => CrmWorkspace::defaultFor($owner)->id,
            'name' => 'Azim',
            'status' => 'lead',
        ]);

        $otherTeam = Team::factory()->create();
        $foreignWorkspace = $this->createRow(CrmWorkspace::class, $owner->id, $otherTeam->id);

        $this->actingAs($owner)->post(route('crm.deals.store'), [
            'customer_id' => $customer->id,
            'title' => 'A sale',
            'amount' => 100,
            'crm_workspace_id' => $foreignWorkspace->id,
        ])->assertSessionHasErrors('crm_workspace_id');
    }
}
