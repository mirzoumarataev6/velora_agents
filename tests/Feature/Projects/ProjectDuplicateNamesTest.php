<?php

namespace Tests\Feature\Projects;

use App\Models\ProjectBoard;
use App\Models\User;
use App\Services\Projects\ProjectBoards;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PHASE 2 item 11: validation only, not a DB unique index — see
 * BoardController::assertUniqueColumnName()'s own docblock for why a
 * migration would be unsafe to ship as-is (this dev database already has a
 * real board with two case-differing duplicate labels).
 */
class ProjectDuplicateNamesTest extends TestCase
{
    use RefreshDatabase;

    private function board(User $owner): ProjectBoard
    {
        return app(ProjectBoards::class)->create($owner->current_team_id, $owner->id, 'Dup check', null, 'team');
    }

    public function test_a_second_column_with_the_same_name_case_insensitively_is_rejected(): void
    {
        $owner = User::factory()->create();
        $board = $this->board($owner);

        $this->actingAs($owner)
            ->post(route('projects.columns.store', $board), ['name' => 'Review'])
            ->assertRedirect();

        $this->post(route('projects.columns.store', $board), ['name' => 'review'])
            ->assertSessionHasErrors(['name' => __('projects.errors.duplicate_column_name')]);

        $this->assertSame(1, $board->columns()->whereRaw('LOWER(name) = ?', ['review'])->count());
    }

    public function test_renaming_a_column_to_collide_with_another_is_rejected_but_keeping_its_own_name_is_fine(): void
    {
        $owner = User::factory()->create();
        $board = $this->board($owner);
        $this->actingAs($owner)->post(route('projects.columns.store', $board), ['name' => 'Review'])->assertRedirect();
        $target = $board->columns()->where('name', 'Review')->sole();
        $other = $board->columns()->first();

        $this->patch(route('projects.columns.update', [$board, $target]), ['name' => $other->name])
            ->assertSessionHasErrors(['name' => __('projects.errors.duplicate_column_name')]);

        // Saving with its OWN unchanged name must not trip over itself.
        $this->patch(route('projects.columns.update', [$board, $target]), ['name' => 'Review', 'wip_limit' => 5])
            ->assertRedirect()->assertSessionDoesntHaveErrors();
    }

    public function test_the_same_name_is_fine_on_a_different_board(): void
    {
        $owner = User::factory()->create();
        $boardA = $this->board($owner);
        $boardB = app(ProjectBoards::class)->create($owner->current_team_id, $owner->id, 'Other board', null, 'team');

        $this->actingAs($owner)
            ->post(route('projects.columns.store', $boardA), ['name' => 'Review'])
            ->assertRedirect()->assertSessionDoesntHaveErrors();
        $this->post(route('projects.columns.store', $boardB), ['name' => 'Review'])
            ->assertRedirect()->assertSessionDoesntHaveErrors();
    }

    public function test_a_second_label_with_the_same_name_case_insensitively_is_rejected(): void
    {
        $owner = User::factory()->create();
        $board = $this->board($owner);

        $this->actingAs($owner)
            ->post(route('projects.labels.store', $board), ['name' => 'Urgent', 'color' => 'rose'])
            ->assertRedirect();

        $this->post(route('projects.labels.store', $board), ['name' => 'URGENT', 'color' => 'blue'])
            ->assertSessionHasErrors(['name' => __('projects.errors.duplicate_label_name')]);

        $this->assertSame(1, $board->labels()->count());
    }
}
