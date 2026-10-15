<?php

namespace Tests\Feature\Frontend;

use Tests\TestCase;

/**
 * No Blade view may spell out `x-init="init()"`.
 *
 * Alpine already calls a data object's own `init()` during x-data setup
 * (`reactiveData['init'] && evaluate(el, reactiveData['init'])`), so writing
 * it again in x-init runs init() TWICE — against a `destroy()` that Alpine
 * still calls exactly ONCE.
 *
 * That asymmetry is what this test exists to stop, because the failure is
 * invisible on the page that causes it. The conversations inbox opened a 15s
 * `setInterval` in init() and stored the id in `this._pollTimer`; the second
 * init() overwrote the id, so destroy() cleared the second poller and left the
 * first one running forever. Measured on a seeded database: two
 * `/conversations/list` requests per inbox paint instead of one, and one
 * immortal poller left behind per visit — still polling from /projects and
 * /crm, pages that have no inbox. Those strays queue on the same PHP worker
 * the owner's next navigation needs, which is what made a burst of nav clicks
 * look like it was replaying work seconds after the clicking stopped.
 *
 * A browser test would prove the live behaviour better, and PHPUnit cannot
 * drive one here — so this guards the source pattern instead, which is the
 * part that actually regressed. The real timings live in the task report.
 *
 * Deliberately a plain substring scan rather than an HTML parse: Blade views
 * are templates, not parseable HTML, and the whole point is to catch the
 * literal attribute a developer is about to type.
 */
class AlpineInitIsNotDoubledTest extends TestCase
{
    public function test_no_view_calls_init_from_x_init(): void
    {
        $offenders = [];

        $views = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($views as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            // Blade comments are blanked rather than dropped, so the line
            // numbers below still point at the real file. Without this the
            // scan trips over prose: the note in the inbox explaining why the
            // attribute is absent has to be able to name the thing it forbids.
            $source = preg_replace_callback(
                '/\{\{--.*?--\}\}/s',
                fn ($m) => preg_replace('/[^\n]/', ' ', $m[0]),
                file_get_contents($file->getPathname())
            );

            foreach (explode("\n", $source) as $number => $line) {
                // Only the self-call is wrong. `x-init="start()"`, or any other
                // method name, is a normal and correct use of the directive —
                // Alpine auto-calls `init` and nothing else.
                if (preg_match('/x-init\s*=\s*"\s*init\(\s*\)\s*"/', $line)) {
                    $offenders[] = str_replace(base_path().'/', '', $file->getPathname()).':'.($number + 1);
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'These views call init() from x-init, which runs it twice (Alpine already',
            'calls init() itself) while destroy() still runs once. Delete the attribute:',
            ...$offenders,
        ]));
    }
}
