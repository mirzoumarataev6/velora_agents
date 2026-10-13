<?php

namespace Tests\Feature\Teams;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\CrmStage;
use App\Models\CrmWorkspace;
use App\Models\Message;
use App\Models\Role;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Permissions\PermissionCatalog as P;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TeamAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public static function roles(): array
    {
        return [['owner'], ['admin'], ['operator'], ['viewer']];
    }

    private function member(User $owner, string $slug): User
    {
        if ($slug === P::OWNER) {
            return $owner;
        }

        $member = User::factory()->create();
        TeamMembership::create([
            'team_id' => $owner->current_team_id,
            'user_id' => $member->id,
            'role_id' => Role::where('team_id', $owner->current_team_id)->where('slug', $slug)->sole()->id,
            'joined_at' => now(),
        ]);
        $member->forceFill(['current_team_id' => $owner->current_team_id])->save();

        return $member;
    }

    #[DataProvider('roles')]
    public function test_catalog_gates_enforce_each_role_in_the_requested_team(string $slug): void
    {
        $owner = User::factory()->create();
        $member = $this->member($owner, $slug);
        $foreignOwner = User::factory()->create();

        foreach (P::all() as $permission) {
            $expected = in_array($permission, P::defaultMatrix()[$slug], true);
            $this->assertSame($expected, Gate::forUser($member)->allows($permission), $permission);
            $this->assertSame($expected, Gate::forUser($member)->allows($permission, [$owner->current_team_id]), $permission);
            $this->assertFalse(Gate::forUser($member)->allows($permission, [$foreignOwner->current_team_id]));
        }
    }

    #[DataProvider('roles')]
    public function test_every_resource_policy_uses_team_membership_and_the_required_permission(string $slug): void
    {
        $owner = User::factory()->create();
        $member = $this->member($owner, $slug);
        $foreign = User::factory()->create();
        $matrix = [
            'Agent' => ['view' => P::AGENTS_VIEW, 'update' => P::AGENTS_UPDATE, 'delete' => P::AGENTS_DELETE],
            'Channel' => ['view' => P::CHANNELS_VIEW, 'update' => P::CHANNELS_MANAGE, 'delete' => P::CHANNELS_MANAGE],
            'Conversation' => ['view' => P::CONVERSATIONS_VIEW, 'reply' => P::CONVERSATIONS_REPLY, 'update' => P::CONVERSATIONS_TAKEOVER, 'delete' => P::CONVERSATIONS_TAKEOVER],
            'Dataset' => ['view' => P::CATALOG_VIEW, 'update' => P::CATALOG_MANAGE, 'delete' => P::CATALOG_MANAGE],
            'DatabaseConnection' => ['view' => P::CATALOG_VIEW, 'update' => P::CATALOG_MANAGE, 'delete' => P::CATALOG_MANAGE],
            'KnowledgeSource' => ['view' => P::AGENTS_VIEW, 'update' => P::AGENTS_UPDATE, 'delete' => P::AGENTS_UPDATE],
            'KnowledgeChunk' => ['view' => P::AGENTS_VIEW, 'delete' => P::AGENTS_UPDATE],
            'BiChatMessage' => ['view' => P::ANALYTICS_VIEW, 'update' => P::ANALYTICS_MANAGE],
            'BiColumn' => ['view' => P::ANALYTICS_VIEW, 'update' => P::ANALYTICS_MANAGE],
            'CrmNote' => ['delete' => P::ANALYTICS_MANAGE],
            'CrmStage' => ['update' => P::ANALYTICS_MANAGE, 'delete' => P::ANALYTICS_MANAGE],
        ];
        foreach (['BiConnection', 'BiDashboard', 'BiDataset', 'BiSource', 'BiWidget', 'Customer', 'Deal', 'Task', 'Lead', 'CrmWorkspace'] as $model) {
            $matrix[$model] = ['view' => P::ANALYTICS_VIEW, 'update' => P::ANALYTICS_MANAGE, 'delete' => P::ANALYTICS_MANAGE];
        }
        $matrix['BiConnection']['test'] = P::ANALYTICS_MANAGE;
        $matrix['BiDataset']['query'] = P::ANALYTICS_VIEW;

        foreach ($matrix as $name => $abilities) {
            $class = 'App\\Models\\'.$name;
            $row = (new $class)->forceFill(['team_id' => $owner->current_team_id, 'user_id' => $owner->id]);
            foreach ($abilities as $ability => $permission) {
                $expected = in_array($permission, P::defaultMatrix()[$slug], true);
                $this->assertSame($expected, Gate::forUser($member)->allows($ability, $row), "{$slug} {$name}.{$ability}");

                // Attribution cannot override team isolation, even for Owner.
                $row->forceFill(['team_id' => $foreign->current_team_id, 'user_id' => $member->id]);
                $this->assertFalse(Gate::forUser($member)->allows($ability, $row), "foreign {$name}.{$ability}");
                $row->forceFill(['team_id' => null]);
                $this->assertFalse(Gate::forUser($member)->allows($ability, $row), "unassigned {$name}.{$ability}");
                $row->forceFill(['team_id' => $owner->current_team_id]);
            }
        }
    }

    public function test_owner_bypass_preserves_resource_invariants_and_unknown_abilities_are_denied(): void
    {
        $owner = User::factory()->create();
        $workspace = (new CrmWorkspace)->forceFill(['team_id' => $owner->current_team_id, 'user_id' => $owner->id, 'is_default' => true]);
        $stage = (new CrmStage)->forceFill(['team_id' => $owner->current_team_id, 'user_id' => $owner->id, 'role' => 'initial']);

        $this->assertFalse(Gate::forUser($owner)->allows('delete', $workspace));
        $this->assertFalse(Gate::forUser($owner)->allows('update', $workspace));
        $this->assertFalse(Gate::forUser($owner)->allows('delete', $stage));
        $this->assertFalse(Gate::forUser($owner)->allows('unknown.permission', $workspace));
    }

    public function test_class_checks_fail_closed_without_a_valid_active_membership(): void
    {
        $user = User::factory()->create();
        foreach ([null, User::factory()->create()->current_team_id] as $teamId) {
            $user->forceFill(['current_team_id' => $teamId])->save();
            $this->assertFalse(Gate::forUser($user)->allows('create', Agent::class));
            $this->assertFalse(Gate::forUser($user)->allows('viewAny', Agent::class));
        }
    }

    public function test_messages_authorize_through_their_conversation_and_not_the_active_owner_role(): void
    {
        $owner = User::factory()->create();
        $viewer = $this->member($owner, P::VIEWER);
        $conversation = (new Conversation)->forceFill(['team_id' => $owner->current_team_id]);
        $message = (new Message)->setRelation('conversation', $conversation);
        $this->assertTrue(Gate::forUser($viewer)->allows('view', $message));
        $this->assertFalse(Gate::forUser($viewer)->allows('create', [Message::class, $conversation]));
        $message->setRelation('conversation', null);
        $this->assertFalse(Gate::forUser($owner)->allows('view', $message));
    }

    public function test_a_foreign_or_template_owner_role_cannot_grant_team_access(): void
    {
        $owner = User::factory()->create();
        $member = $this->member($owner, P::VIEWER);
        $foreignOwnerRole = Role::where('team_id', User::factory()->create()->current_team_id)->where('is_owner', true)->sole();
        TeamMembership::where('team_id', $owner->current_team_id)->where('user_id', $member->id)->update(['role_id' => $foreignOwnerRole->id]);
        $member->flushPermissionCache();
        $this->assertFalse(Gate::forUser($member)->allows(P::BILLING_MANAGE, [$owner->current_team_id]));
        $this->assertNull($member->activeTeamId());
    }
}
