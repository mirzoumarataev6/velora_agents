<?php

namespace Tests\Feature\Frontend;

use Tests\TestCase;

/**
 * Regression coverage for the double-submit guard: a fast double-click, or a
 * second Enter before the first response lands, used to resubmit a plain
 * form for real — a duplicate agent, invitation, task card, or CRM note, and
 * on the second response often a confusing error (a unique-constraint hit, a
 * token already consumed) rather than an obvious dup.
 *
 * PHPUnit cannot click a button twice or drive Turbo's lifecycle, so — same
 * approach as AlpineInitIsNotDoubledTest and TurboNavigationTest — this
 * guards the source patterns the fix actually depends on: that the guard is
 * registered, that it is registered in the right place relative to the
 * handlers it must not fight with, and that both opt-outs it promises exist.
 * Manual click-through steps live in the task report.
 */
class DoubleSubmitGuardTest extends TestCase
{
    private function appJs(): string
    {
        return file_get_contents(resource_path('js/app.js'));
    }

    private function authLayout(): string
    {
        return file_get_contents(resource_path('views/layouts/auth.blade.php'));
    }

    public function test_dashboard_bootstrap_locks_a_form_on_submit_and_disables_its_controls(): void
    {
        $js = $this->appJs();

        $this->assertStringContainsString("form.setAttribute('data-submit-locked', '')", $js);
        $this->assertStringContainsString('control.disabled = true', $js);
        $this->assertStringContainsString("control.setAttribute('aria-busy', 'true')", $js);
    }

    public function test_dashboard_bootstrap_releases_the_lock_on_submit_end_before_cache_and_pageshow(): void
    {
        $js = $this->appJs();

        $this->assertStringContainsString("addEventListener('turbo:submit-end'", $js);
        $this->assertStringContainsString("addEventListener('turbo:before-cache'", $js);
        $this->assertStringContainsString("addEventListener('pageshow'", $js);
        $this->assertStringContainsString('unlockFormSubmission', $js);
    }

    public function test_dashboard_bootstrap_exposes_both_opt_outs(): void
    {
        $js = $this->appJs();

        // The explicit escape hatch for a form that must stay resubmittable.
        $this->assertStringContainsString("hasAttribute('data-allow-resubmit')", $js);

        // The automatic one: a new-tab/download form is never what this bug
        // duplicates, so it must never be locked.
        $this->assertStringContainsString("form.target === '_blank'", $js);
    }

    /**
     * The guard must never lock a form whose submission was already claimed
     * by something else — `@submit.prevent` (the inbox composer, channel
     * connect forms, …) or the `data-task-toggle` listener just above it in
     * the same file. Both call `preventDefault()` as their first line, so by
     * bubble phase on `document` the guard can tell them apart from a plain
     * form with one `event.defaultPrevented` check — ONE place, not a list
     * of selectors to keep in sync with every self-managed form.
     */
    public function test_dashboard_bootstrap_leaves_already_prevented_submissions_alone(): void
    {
        $js = $this->appJs();

        $this->assertStringContainsString('if (event.defaultPrevented) return;', $js);
    }

    /**
     * Ordering regression guard: `data-task-toggle`'s own listener must stay
     * textually before the central guard's. Both are plain
     * `document.addEventListener('submit', …)` calls, so same-phase
     * listeners on the same target run in registration order — if the
     * central guard were registered FIRST, it would see
     * `event.defaultPrevented === false` (data-task-toggle's own
     * `preventDefault()` not having run yet), lock that toggle form on its
     * very first use, and never unlock it: that form never triggers a Turbo
     * `FormSubmission`, so `turbo:submit-end` never fires for it.
     */
    public function test_data_task_toggle_listener_is_registered_before_the_central_guard(): void
    {
        $js = $this->appJs();

        $toggleAt = strpos($js, 'form[data-task-toggle]');
        $guardAt = strpos($js, 'SUBMIT_CONTROLS_SELECTOR');

        $this->assertNotFalse($toggleAt, 'data-task-toggle listener not found.');
        $this->assertNotFalse($guardAt, 'Central double-submit guard not found.');
        $this->assertLessThan($guardAt, $toggleAt, 'The central guard must be registered after data-task-toggle, or it will deadlock that form.');
    }

    /**
     * GET forms (search/filter resubmission) are not the bug: a second
     * identical GET duplicates nothing, so they must stay unlocked rather
     * than show a stuck button for no reason.
     */
    public function test_dashboard_bootstrap_does_not_lock_get_forms(): void
    {
        $this->assertStringContainsString("form.method.toLowerCase() === 'get'", $this->appJs());
    }

    /**
     * The auth layout (login/register/forgot/reset) deliberately loads no
     * app.js/Turbo — see its own note beside @vite — so it needs its own
     * copy of the same rule rather than sharing the dashboard's. This only
     * guards that the copy exists and keeps the same opt-outs; see the
     * layout's own docblock for why its release story differs (pageshow
     * only, no turbo:submit-end).
     */
    public function test_auth_layout_has_its_own_copy_of_the_guard_and_opt_outs(): void
    {
        $html = $this->authLayout();

        $this->assertStringContainsString("form.setAttribute('data-submit-locked', '')", $html);
        $this->assertStringContainsString("hasAttribute('data-allow-resubmit')", $html);
        $this->assertStringContainsString("addEventListener('pageshow'", $html);
    }

    /**
     * login/register already own their own disable/re-enable via
     * `handleAuthSubmit` (first line: `event.preventDefault()`). The auth
     * layout's guard must not also lock those, for the same reason as
     * data-task-toggle above.
     */
    public function test_auth_layout_guard_also_leaves_already_prevented_submissions_alone(): void
    {
        $this->assertStringContainsString('if (event.defaultPrevented) return;', $this->authLayout());
    }
}
