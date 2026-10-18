<?php

namespace Tests\Feature\Projects;

use App\Models\Task;
use App\Models\User;
use App\Services\Projects\ProjectBoards;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PHASE 2 item 14: after a drop, board.blade.php's drop() used to rewrite
 * the column counter as `count.firstChild.textContent = String(newCount)`.
 * Two bugs: (1) the span's content ("12 / 20") is one continuous text run —
 * Blade's `@if` here emits plain text with no element boundary, so
 * overwriting firstChild replaced the whole thing and dropped the " / 20"
 * suffix outright; (2) the replacement count came from
 * `querySelectorAll('[data-project-task-id]').length` — only the loaded
 * page's cards, which undercounts a column once it's paginated (see item
 * 12). The fix renders the counter FROM `data-column-total`/`data-wip-limit`
 * (bumped by ±1 in JS, never re-queried from the DOM), which this test
 * confirms are present and correctly valued; the JS bump itself has no
 * runtime here to execute against (no frontend framework, no JS test
 * runner — see CLAUDE.md) and was checked manually in a real browser, see
 * the PHASE 2 final report.
 */
class ProjectColumnCounterMarkupTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_count_span_carries_the_total_and_limit_as_data_attributes(): void
    {
        $owner = User::factory()->create();
        $board = app(ProjectBoards::class)->create($owner->current_team_id, $owner->id, 'Board', null, 'team');
        $column = $board->columns()->first();
        $column->forceFill(['wip_limit' => 5])->save();
        Task::factory()->for($owner)->count(3)->create(['project_board_id' => $board->id, 'project_column_id' => $column->id]);

        $html = $this->actingAs($owner)->get(route('projects.boards.show', $board))->assertOk()->getContent();

        $this->assertStringContainsString('data-column-total="3"', $html);
        $this->assertStringContainsString('data-wip-limit="5"', $html);
        // Visible text must still read the existing "n / limit" format.
        $this->assertMatchesRegularExpression('/data-column-count[^>]*>\s*3\s*\/\s*5\s*</', $html);
    }

    public function test_a_column_with_no_wip_limit_carries_an_empty_limit_attribute(): void
    {
        $owner = User::factory()->create();
        $board = app(ProjectBoards::class)->create($owner->current_team_id, $owner->id, 'Board', null, 'team');
        $column = $board->columns()->first();
        Task::factory()->for($owner)->count(2)->create(['project_board_id' => $board->id, 'project_column_id' => $column->id]);

        $html = $this->actingAs($owner)->get(route('projects.boards.show', $board))->assertOk()->getContent();

        $this->assertStringContainsString('data-column-total="2"', $html);
        $this->assertStringContainsString('data-wip-limit=""', $html);
        $this->assertMatchesRegularExpression('/data-column-count[^>]*>\s*2\s*</', $html);
    }
}
