<?php

namespace Tests\Feature\Projects;

use App\Models\Role;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Projects\ProjectBoards;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProjectBoardDefaultNamesTest extends TestCase
{
    use RefreshDatabase;

    /** A non-owner member of the owner's team, with no locale of their own. */
    private function memberWithNoLocale(User $owner): User
    {
        $member = User::factory()->create(['locale' => null]);
        TeamMembership::create([
            'team_id' => $owner->current_team_id,
            'user_id' => $member->id,
            'role_id' => Role::where('team_id', $owner->current_team_id)->where('slug', 'operator')->sole()->id,
            'joined_at' => now(),
        ]);

        return $member;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function localeProvider(): array
    {
        return [
            'ru' => ['ru'],
            'tg' => ['tg'],
            'en' => ['en'],
        ];
    }

    #[DataProvider('localeProvider')]
    public function test_default_board_and_columns_are_named_in_the_active_locale(string $locale): void
    {
        App::setLocale($locale);
        $owner = User::factory()->create();

        $board = app(ProjectBoards::class)->defaultForTeam($owner->current_team_id, $owner->id);

        $this->assertSame(__('projects.defaults.board_name', [], $locale), $board->name);

        $columns = $board->columns()->orderBy('position')->get();
        $this->assertSame(__('projects.defaults.column_todo', [], $locale), $columns[0]->name);
        $this->assertSame(__('projects.defaults.column_doing', [], $locale), $columns[1]->name);
        $this->assertSame(__('projects.defaults.column_done', [], $locale), $columns[2]->name);
        $this->assertFalse($columns[0]->is_done);
        $this->assertFalse($columns[1]->is_done);
        $this->assertTrue($columns[2]->is_done);
    }

    #[DataProvider('localeProvider')]
    public function test_a_manually_created_board_also_gets_locale_translated_columns(string $locale): void
    {
        App::setLocale($locale);
        $owner = User::factory()->create();

        $board = app(ProjectBoards::class)->create($owner->current_team_id, $owner->id, 'Custom board');

        $columns = $board->columns()->orderBy('position')->get();
        $this->assertSame(__('projects.defaults.column_todo', [], $locale), $columns[0]->name);
        $this->assertSame(__('projects.defaults.column_doing', [], $locale), $columns[1]->name);
        $this->assertSame(__('projects.defaults.column_done', [], $locale), $columns[2]->name);
    }

    /**
     * defaultForTeam() also runs from Task::booted() — queue jobs, the agent
     * pipeline, console commands, DemoSeeder — where nothing has ever called
     * SetLocale, so the process is stuck on config('app.locale'). The creator's
     * own saved preference must win there regardless of that process locale.
     */
    public function test_default_names_follow_the_creators_saved_locale_not_the_process_locale(): void
    {
        App::setLocale('en');
        $owner = User::factory()->create(['locale' => 'tg']);

        $board = app(ProjectBoards::class)->defaultForTeam($owner->current_team_id, $owner->id);

        $this->assertSame(__('projects.defaults.board_name', [], 'tg'), $board->name);
        $columns = $board->columns()->orderBy('position')->get();
        $this->assertSame(__('projects.defaults.column_todo', [], 'tg'), $columns[0]->name);
        $this->assertSame(__('projects.defaults.column_doing', [], 'tg'), $columns[1]->name);
        $this->assertSame(__('projects.defaults.column_done', [], 'tg'), $columns[2]->name);
    }

    /**
     * A creator with no saved locale of their own (e.g. never set one, or a
     * system write with no acting user) falls back to the team owner's saved
     * locale, and only then to the process locale — never trusted first, per
     * the WHY comment on ProjectBoards::resolveLocale().
     */
    public function test_default_names_fall_back_to_the_owners_locale_when_the_creator_has_none(): void
    {
        App::setLocale('en');
        $owner = User::factory()->create(['locale' => 'ru']);
        $member = $this->memberWithNoLocale($owner);

        $board = app(ProjectBoards::class)->defaultForTeam($owner->current_team_id, $member->id);

        $this->assertSame(__('projects.defaults.board_name', [], 'ru'), $board->name);
        $this->assertSame(__('projects.defaults.column_todo', [], 'ru'), $board->columns()->orderBy('position')->first()->name);
    }

    public function test_default_name_keys_exist_in_every_supported_locale(): void
    {
        $keys = ['board_name', 'column_todo', 'column_doing', 'column_done'];

        foreach (['ru', 'tg', 'en'] as $locale) {
            $translations = require base_path("resources/lang/{$locale}/projects.php");
            foreach ($keys as $key) {
                $this->assertArrayHasKey($key, $translations['defaults'], "Missing projects.defaults.{$key} for locale [{$locale}]");
                $this->assertNotSame('', trim($translations['defaults'][$key]), "Empty projects.defaults.{$key} for locale [{$locale}]");
            }
        }
    }
}
