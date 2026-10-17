<?php

namespace Tests\Feature\Projects;

use App\Models\User;
use App\Services\Projects\ProjectBoards;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProjectBoardDefaultNamesTest extends TestCase
{
    use RefreshDatabase;

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
     * A board's name is plain text once stored — it is never retranslated when the
     * owner later switches language, because the name is editable and a silent
     * rewrite would clobber a name the owner chose for themselves. See the WHY
     * comment in ProjectBoards::defaultForTeam().
     */
    public function test_default_board_name_is_not_retranslated_after_a_later_locale_switch(): void
    {
        App::setLocale('ru');
        $owner = User::factory()->create();
        $board = app(ProjectBoards::class)->defaultForTeam($owner->current_team_id, $owner->id);
        $this->assertSame(__('projects.defaults.board_name', [], 'ru'), $board->name);

        App::setLocale('en');
        $sameBoard = app(ProjectBoards::class)->defaultForTeam($owner->current_team_id, $owner->id);

        $this->assertSame($board->id, $sameBoard->id);
        $this->assertSame(__('projects.defaults.board_name', [], 'ru'), $sameBoard->name);
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
