<?php

namespace Tests\Feature\Teams;

use App\Models\Role;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Permissions\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * D-03: /no-access used to offer only "Sign out" — a dead end for a member
 * of two or more teams whose CURRENT team's role holds none of
 * HomeDestination's known permissions, since signing out never touches
 * current_team_id (AuthController::logout() only calls Auth::logout() and
 * invalidates the session), so signing back in returns to the same team
 * and the same page. This gives a way off it: the exact membership
 * snapshot the sidebar's own switcher reads
 * (partials/team-switcher.blade.php's User::cachedTeamMemberships()),
 * filtered down to every team but the current one.
 */
class NoAccessTeamSwitcherTest extends TestCase
{
    use RefreshDatabase;

    private function noPermissionRole(int $teamId): Role
    {
        $role = Role::create(['team_id' => $teamId, 'slug' => 'empty-'.uniqid(), 'name' => 'Empty']);
        $role->syncPermissions([]);

        return $role;
    }

    private function crmViewRole(int $teamId): Role
    {
        $role = Role::create(['team_id' => $teamId, 'slug' => 'crm-'.uniqid(), 'name' => 'CRM']);
        $role->syncPermissions([PermissionCatalog::CRM_VIEW]);

        return $role;
    }

    private function join(User $user, int $teamId, Role $role): void
    {
        TeamMembership::create([
            'team_id' => $teamId, 'user_id' => $user->id,
            'role_id' => $role->id, 'joined_at' => now(),
        ]);
    }

    /**
     * Required case (a): a switch form for team B, built from the user's
     * own name in team B, but nothing offering team A — the current team
     * is exactly why this page is being shown, so offering to "switch" to
     * it is never shown. `createQuietly()` on the member skips
     * UserObserver's personal-team creation so the only two memberships in
     * play are the ones this test builds itself.
     */
    public function test_the_no_access_page_offers_the_other_team_but_not_the_current_one(): void
    {
        $teamAOwner = User::factory()->create();
        $teamBOwner = User::factory()->create();
        $member = User::factory()->createQuietly();

        $this->join($member, $teamAOwner->current_team_id, $this->noPermissionRole($teamAOwner->current_team_id));
        $this->join($member, $teamBOwner->current_team_id, $this->crmViewRole($teamBOwner->current_team_id));
        $member->forceFill(['current_team_id' => $teamAOwner->current_team_id])->save();

        $response = $this->actingAs($member)->get(route('home.no-access'));

        $response->assertOk();
        $response->assertSee(route('teams.switch'), false);
        $response->assertSee('value="'.$teamBOwner->current_team_id.'"', false);
        $response->assertDontSee('value="'.$teamAOwner->current_team_id.'"', false);
    }

    /** Required case (b): posting the new form actually switches, landing on team B's own home — never back on /no-access. */
    public function test_posting_the_switch_form_from_no_access_lands_on_the_new_teams_home(): void
    {
        $teamAOwner = User::factory()->create();
        $teamBOwner = User::factory()->create();
        $member = User::factory()->createQuietly();

        $this->join($member, $teamAOwner->current_team_id, $this->noPermissionRole($teamAOwner->current_team_id));
        $this->join($member, $teamBOwner->current_team_id, $this->crmViewRole($teamBOwner->current_team_id));
        $member->forceFill(['current_team_id' => $teamAOwner->current_team_id])->save();

        $response = $this->actingAs($member)->from(route('home.no-access'))
            ->post(route('teams.switch'), ['team_id' => $teamBOwner->current_team_id]);

        $response->assertRedirect(route('crm.board'));
        $this->assertSame($teamBOwner->current_team_id, $member->fresh()->current_team_id);
    }

    /**
     * Required case (c): a member of exactly one team sees the page exactly
     * as it was before this change — no heading, no switch form, Sign out
     * still there. `createQuietly()` again, so this member truly has only
     * the one membership this test gives them.
     */
    public function test_a_single_team_member_sees_no_switcher_and_still_sees_sign_out(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->createQuietly();
        $this->join($member, $owner->current_team_id, $this->noPermissionRole($owner->current_team_id));
        $member->forceFill(['current_team_id' => $owner->current_team_id])->save();

        $response = $this->actingAs($member)->get(route('home.no-access'));

        $response->assertOk();
        $response->assertDontSee(route('teams.switch'), false);
        $response->assertDontSee(__('common.no_access.switch_heading'));
        $response->assertSee(__('common.no_access.sign_out'));
    }

    /**
     * Required case (d): the other team's name is attacker-controlled
     * (any member can rename a team they do not own... and even if they
     * could not, this name rides straight from the database either way) —
     * it must reach the page through Blade's ordinary `{{ }}` escaping,
     * never raw.
     */
    public function test_the_other_teams_name_is_html_escaped(): void
    {
        $teamAOwner = User::factory()->create();
        $teamB = Team::factory()->create(['name' => '<b>Evil</b> Co']);
        Role::copyTemplatesFor($teamB);
        $member = User::factory()->createQuietly();

        $this->join($member, $teamAOwner->current_team_id, $this->noPermissionRole($teamAOwner->current_team_id));
        $this->join($member, $teamB->id, $this->crmViewRole($teamB->id));
        $member->forceFill(['current_team_id' => $teamAOwner->current_team_id])->save();

        $response = $this->actingAs($member)->get(route('home.no-access'));

        $response->assertOk();
        $response->assertDontSee('<b>Evil</b> Co', false);
        $response->assertSee('&lt;b&gt;Evil&lt;/b&gt; Co', false);
    }
}
