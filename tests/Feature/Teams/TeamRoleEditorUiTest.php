<?php

namespace Tests\Feature\Teams;

use App\Models\Role;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Permissions\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamRoleEditorUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_has_a_link_and_sees_the_complete_permission_matrix(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)->get(route('settings.index'))
            ->assertOk()->assertSee(route('teams.roles.index'));

        $response = $this->get(route('teams.roles.index'));
        $response->assertOk();
        $response->assertSee('data-i18n="settings.roles.enforced"', false);
        foreach (PermissionCatalog::all() as $permission) {
            $response->assertSee('value="'.$permission.'"', false);
            $response->assertSee(__('settings.roles.permissions.'.$permission));
        }
    }

    public function test_owner_bundle_is_read_only_and_a_custom_role_can_be_edited(): void
    {
        $owner = User::factory()->create();
        $ownerRole = Role::where('team_id', $owner->current_team_id)->where('slug', PermissionCatalog::OWNER)->sole();
        $custom = Role::create(['team_id' => $owner->current_team_id, 'name' => 'Coach', 'slug' => 'coach-custom']);
        $custom->syncPermissions([PermissionCatalog::AGENTS_VIEW]);

        $ownerPage = $this->actingAs($owner)->get(route('teams.roles.index', ['edit' => $ownerRole->id]));
        $ownerPage->assertOk();
        $ownerPage->assertSee(__('settings.roles.owner_protected'));
        $ownerPage->assertDontSee('id="team-role-form"', false);

        $customPage = $this->get(route('teams.roles.index', ['edit' => $custom->id]));
        $customPage->assertOk();
        $customPage->assertSee('value="Coach"', false);
        $customPage->assertSee(route('teams.roles.update', $custom));
        $customPage->assertSee('value="agents.view"', false);
    }

    public function test_operator_has_no_role_editor_link(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $role = Role::where('team_id', $owner->current_team_id)->where('slug', PermissionCatalog::OPERATOR)->sole();
        TeamMembership::create([
            'team_id' => $owner->current_team_id,
            'user_id' => $member->id,
            'role_id' => $role->id,
            'joined_at' => now(),
        ]);
        $member->forceFill(['current_team_id' => $owner->current_team_id])->save();

        $this->actingAs($member)->get(route('settings.index'))
            ->assertOk()->assertDontSee(route('teams.roles.index'));
    }

    public function test_invalid_permission_payload_can_rerender_the_form_without_a_server_error(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)->from(route('teams.roles.index'))
            ->post(route('teams.roles.store'), ['name' => 'Coach', 'permissions' => 'bad'])
            ->assertSessionHasErrors('permissions', null, 'roles');

        $this->get(route('teams.roles.index'))->assertOk();
    }
}
