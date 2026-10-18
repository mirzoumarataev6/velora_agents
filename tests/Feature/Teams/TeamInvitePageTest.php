<?php

namespace Tests\Feature\Teams;

use App\Models\Invitation;
use App\Models\Role;
use App\Models\User;
use App\Services\Teams\CreatesTeam;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PHASE 3 item 20, both halves:
 *
 * 1. The invite form's role <select> never marked any <option> `selected`
 *    on a fresh load — old('role_id') is null then, and (int) null === an
 *    id is never true — so the browser fell back to whichever option was
 *    FIRST in the DOM, which is Owner (roles are queried orderBy('id'),
 *    Owner always first). The default is now the role with the FEWEST
 *    permissions, Owner excluded outright.
 *
 * 2. The pending-invitation expiry date used Carbon's translatedFormat()
 *    with 'd MMM Y' — not a valid format string for it (it reuses PHP's
 *    own date() tokens, where 'M' is a single-letter "short month" token;
 *    three in a row are three independent tokens, not one CLDR-style
 *    "MMM" token) — rendering as the month name repeated and mashed
 *    together: "20 октябряоктября10 2026". Reproduced directly against
 *    Carbon before fixing; see the commit for the repro.
 */
class TeamInvitePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_invite_form_defaults_to_the_least_privileged_role_not_owner(): void
    {
        $owner = User::factory()->create();
        app(CreatesTeam::class)->create($owner, 'Sales');

        $html = $this->actingAs($owner)->get(route('teams.index'))->assertOk()->getContent();

        $ownerRole = Role::where('team_id', $owner->current_team_id)->where('is_owner', true)->sole();
        $viewerRole = Role::where('team_id', $owner->current_team_id)->where('slug', 'viewer')->sole();

        $this->assertStringNotContainsString(
            'value="'.$ownerRole->id.'" selected',
            $html,
            'The Owner role must never be the silent default on the invite form.'
        );
        $this->assertStringContainsString('value="'.$viewerRole->id.'" selected', $html);
    }

    public function test_an_old_role_id_from_a_failed_submission_still_wins_over_the_default(): void
    {
        $owner = User::factory()->create();
        app(CreatesTeam::class)->create($owner, 'Sales');
        $adminRole = Role::where('team_id', $owner->current_team_id)->where('slug', 'admin')->sole();

        // An invalid email fails validation and redirects back with old()
        // input — the admin choice must survive that round trip rather
        // than being silently reset to the computed default.
        $this->actingAs($owner)->from(route('teams.index'))
            ->post(route('teams.invite'), ['email' => 'not-an-email', 'role_id' => $adminRole->id])
            ->assertSessionHasErrorsIn('team', ['email']);

        $html = $this->get(route('teams.index'))->assertOk()->getContent();

        $this->assertStringContainsString('value="'.$adminRole->id.'" selected', $html);
    }

    public function test_a_pending_invitations_expiry_date_renders_the_month_name_once(): void
    {
        $owner = User::factory()->create();
        app(CreatesTeam::class)->create($owner, 'Sales');
        $role = Role::where('team_id', $owner->current_team_id)->where('slug', 'viewer')->sole();
        Invitation::factory()->create([
            'team_id' => $owner->current_team_id,
            'role_id' => $role->id,
            'invited_by' => $owner->id,
            'email' => 'pending@example.test',
            'expires_at' => now()->addDays(7),
        ]);

        $html = $this->actingAs($owner)->get(route('teams.index'))->assertOk()->getContent();

        $expected = now()->addDays(7)->translatedFormat('d F Y');
        $this->assertStringContainsString($expected, $html);
        // The garbled shape is the month's own translated name immediately
        // repeating itself — assert that specific failure mode is gone,
        // not just that SOME text renders.
        $monthName = now()->addDays(7)->translatedFormat('F');
        $this->assertStringNotContainsString($monthName.$monthName, $html);
    }
}
