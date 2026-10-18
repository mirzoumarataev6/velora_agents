<?php

namespace Tests\Feature\Projects;

use App\Models\ProjectBoard;
use App\Models\Role;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Projects\ProjectBoards;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PHASE 1 item 6: a debug 403/500 page renders file paths and, for a failed
 * query, the SQL itself — information an attacker probing the app, not just
 * an honest owner, would see. `.env.example` now ships APP_DEBUG=false (see
 * CLAUDE.md's Deployment section); this is the other half — proving the
 * Projects routes themselves carry nothing in app code that re-adds debug
 * detail to a JSON error body regardless of the config() value. There is no
 * such code here (grepped: nothing under app/ writes getFile()/getTrace()/
 * a 'trace' or 'file' key into a response) — this test is what would catch
 * it if that ever changed, and proves Laravel's own debug=false JSON
 * rendering is what's actually in effect for this app's routes.
 */
class ProjectErrorResponseDebugLeakTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_403_json_response_carries_no_file_or_trace_keys_when_debug_is_off(): void
    {
        config(['app.debug' => false]);

        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        TeamMembership::create([
            'team_id' => $owner->current_team_id,
            'user_id' => $viewer->id,
            'role_id' => Role::where('team_id', $owner->current_team_id)->where('slug', 'viewer')->sole()->id,
            'joined_at' => now(),
        ]);
        $viewer->forceFill(['current_team_id' => $owner->current_team_id])->save();

        $board = app(ProjectBoards::class)->create($owner->current_team_id, $owner->id, 'Private strategy', null, 'private');
        $column = $board->columns()->first();

        $response = $this->actingAs($viewer)
            ->postJson(route('projects.tasks.store', $board), ['column_id' => $column->id, 'title' => 'Should not be allowed']);

        $response->assertForbidden();
        $body = $response->json();

        $this->assertIsArray($body);
        $this->assertArrayNotHasKey('file', $body);
        $this->assertArrayNotHasKey('line', $body);
        $this->assertArrayNotHasKey('trace', $body);
        $this->assertArrayNotHasKey('exception', $body);
        $this->assertStringNotContainsString(base_path(), $response->getContent());
        $this->assertStringNotContainsString('.php', $response->getContent());
    }

    public function test_a_403_html_response_carries_no_file_path_or_sql_when_debug_is_off(): void
    {
        config(['app.debug' => false]);

        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $board = app(ProjectBoards::class)->create($owner->current_team_id, $owner->id, 'Private strategy', null, 'private');

        $response = $this->actingAs($stranger)->get(route('projects.boards.show', $board));

        // 404, not 403: ProjectAccess::authorize() hides a board from a
        // different team behind "not found" rather than confirming it
        // exists — but the leak check is the same either way.
        $response->assertStatus(404);
        $content = $response->getContent();

        $this->assertStringNotContainsString(base_path(), $content);
        $this->assertStringNotContainsString('Stack trace', $content);
        $this->assertStringNotContainsString('SELECT ', $content);
        $this->assertStringNotContainsString('.php:', $content);
    }
}
