<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The task-assignment notification bell (partials/task-bell.blade.php) is
 * chrome, not a page: it renders on every dashboard page from
 * layouts/dashboard.blade.php, fed entirely by client-side JS hitting
 * GET /notifications/tasks (TaskNotificationFeed) — these tests only cover
 * what the SERVER emits (the markup, the config, the i18n namespace, that no
 * page render ever queries the feed itself), never the Alpine behaviour,
 * which is not executable from PHPUnit.
 */
class TaskBellTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_authenticated_page_renders_the_bell_and_its_poll_config(): void
    {
        $user = User::factory()->create();
        Agent::factory()->for($user)->create();

        $html = $this->actingAs($user)->get(route('agents.index'))->assertOk()->getContent();

        // The two bell instances (sidebar header, mobile header — see
        // layouts/dashboard.blade.php) both include this partial — but the
        // taskBell() Alpine component function itself is defined once
        // (@once/@push, same convention as team-switcher.blade.php's
        // teamSwitcher()), not once per instance.
        $this->assertSame(2, substr_count($html, 'x-data="taskBell()"'));
        $this->assertSame(1, substr_count($html, 'function taskBell()'));
        $this->assertStringContainsString('tasksPollUrl:', $html);
        $this->assertStringContainsString(route('notifications.tasks.index'), $html);
        $this->assertStringContainsString('tasksReadAllUrl:', $html);
        $this->assertStringContainsString(route('notifications.tasks.read-all'), $html);
        $this->assertStringContainsString('tasksReadUrlTemplate:', $html);
        $this->assertStringContainsString('activeTeamId:', $html);

        // The namespace the bell's own client-rendered strings come from —
        // present in the i18n bundle, not baked into the markup as English
        // sentences (App\Notifications\TaskAssigned's own "never a prebuilt
        // sentence" convention, carried through to this stage). Compared as
        // @json() itself would encode it (JSON_HEX_*, no
        // JSON_UNESCAPED_UNICODE — Blade's own default), not as a raw
        // substring: a plain Cyrillic/Tajik needle would never match the
        // \uXXXX-escaped form actually in the HTML source, even though the
        // browser decodes it correctly at runtime.
        $this->assertStringContainsString('"assigned_you"', $html);
        $locale = app()->getLocale();
        $expected = json_encode(__('notifications.assigned_you', [], $locale), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
        $this->assertStringContainsString($expected, $html);
    }

    /**
     * Regression guard for a lifecycle bug: the sidebar instance of this
     * component is data-turbo-permanent and survives a Turbo visit, but its
     * teleported menu (a child of the OLD <body>) does not — so `open`
     * could stay true with nothing left showing, and the next click on the
     * bell would only "close" an already-invisible menu. None of this is
     * executable from PHPUnit (it's Alpine runtime behaviour), so this only
     * guards that the markup the fix depends on is actually emitted — same
     * convention as TurboNavigationTest's own string-presence regression
     * tests for client-side-only behaviour.
     */
    public function test_the_bell_closes_itself_on_item_click_and_on_any_turbo_visit(): void
    {
        $user = User::factory()->create();
        Agent::factory()->for($user)->create();

        $html = $this->actingAs($user)->get(route('agents.index'))->assertOk()->getContent();

        // Item click: close(false) alongside markTaskRead(), not a bare
        // navigation that leaves `open` stuck true once the teleported menu
        // is gone.
        $this->assertStringContainsString(
            '@click="$store.shell.markTaskRead(item.id); close(false)"',
            $html
        );

        // Belt-and-suspenders: ANY Turbo visit closes it, not only the one
        // triggered by clicking this component's own items.
        $this->assertStringContainsString('@turbo:before-visit.window="close(false)"', $html);

        // destroy() removes the exact listener init() added — a reused
        // `this._reposition` reference, not a fresh closure that would make
        // removeEventListener a silent no-op.
        $this->assertStringContainsString('this._reposition = () => this.open && this.position();', $html);
        $this->assertStringContainsString("window.removeEventListener('scroll', this._reposition, true);", $html);
        $this->assertStringContainsString("window.removeEventListener('resize', this._reposition);", $html);
    }

    public function test_a_guest_does_not_see_the_bell(): void
    {
        $response = $this->get(route('agents.index'));

        $response->assertRedirect(route('login'));
        $this->assertStringNotContainsString('taskBell()', $response->getContent() ?: '');
    }

    /**
     * The whole point of fetching the feed from JS instead of rendering it
     * server-side: a normal page render must never touch the notifications
     * table at all, for anyone, regardless of how many task-assignment
     * notifications they have.
     */
    public function test_a_dashboard_page_render_never_queries_the_notifications_table(): void
    {
        $user = User::factory()->create();
        Agent::factory()->for($user)->create();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($user)->get(route('agents.index'))->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        foreach ($queries as $query) {
            $this->assertStringNotContainsString(
                'notifications',
                strtolower($query['query']),
                'a dashboard page render queried the notifications table — the bell must be fetched by JS only'
            );
        }
    }
}
