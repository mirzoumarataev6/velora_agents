<?php

namespace App\Services\Bi\Dashboard;

use App\Models\BiDashboard;
use App\Models\BiWidget;
use Illuminate\Support\Facades\DB;

/**
 * Persists a drag.
 *
 * Gridstack reports the whole board after any change — move one card and every
 * card it displaced comes back too — so this is always a bulk write, and it is
 * the only write in the module that touches many rows on behalf of one gesture.
 * Three consequences, all deliberate:
 *
 *  1. IT IS SCOPED BY DASHBOARD, NOT BY OWNER. The incoming ids are filtered to
 *     the widgets this board actually holds, so a payload naming another board's
 *     widget — even one the same owner owns — moves nothing. Ownership alone is
 *     not enough here: dragging on board A must not be able to rearrange board
 *     B, which is a correctness bug rather than a security one, and the cheapest
 *     place to make it impossible is the query that loads the rows.
 *
 *  2. IT CLAMPS. The numbers arrive from a JS library in the browser and are
 *     ordinary untrusted input. `BiWidget::slot()` clamps on read as well, so a
 *     bad row cannot break a board even if it somehow got written.
 *
 *  3. IT WRITES IN ONE TRANSACTION, and silently ignores ids it does not
 *     recognise rather than failing the request. A drag that raced a deletion in
 *     another tab should still save the positions of the cards that remain —
 *     refusing the whole layout because one card is gone would throw away a
 *     rearrangement the owner can see on their screen.
 *
 * `updated_at` is deliberately NOT touched: moving a card is not editing it, and
 * a board reorder should not make every widget look freshly modified.
 */
class LayoutUpdater
{
    /**
     * @param  array<int, array<string, mixed>>  $layout  [{id, x, y, w, h}, …]
     * @return int How many widgets were actually moved.
     */
    public function apply(BiDashboard $dashboard, array $layout): int
    {
        $byId = [];

        foreach ($layout as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $id = (int) ($entry['id'] ?? 0);

            if ($id > 0) {
                $byId[$id] = $entry;
            }
        }

        if ($byId === []) {
            return 0;
        }

        // The scope that makes rule 1 true: only this board's widgets, found by
        // the board's own relation rather than by trusting the ids.
        $widgets = $dashboard->widgets()->whereIn('id', array_keys($byId))->get();

        if ($widgets->isEmpty()) {
            return 0;
        }

        return DB::transaction(function () use ($widgets, $byId) {
            $moved = 0;

            foreach ($widgets as $widget) {
                $slot = $this->clamp($byId[$widget->getKey()]);

                // Skip the rows a drag did not actually change. Gridstack sends
                // the whole board every time, and on a typical move that is
                // thirty unchanged rows — writing them would turn one gesture
                // into thirty pointless UPDATEs.
                if ($this->unchanged($widget, $slot)) {
                    continue;
                }

                // `timestamps = false` on the instance, not an option to
                // save(): Eloquent takes no such option, and passing one is
                // silently ignored — which is how this first shipped bumping
                // every `updated_at` on the board anyway.
                $widget->timestamps = false;
                $widget->forceFill($slot)->saveQuietly();
                $widget->timestamps = true;

                $moved++;
            }

            return $moved;
        });
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array{grid_x: int, grid_y: int, grid_w: int, grid_h: int}
     */
    private function clamp(array $entry): array
    {
        $w = max(BiWidget::MIN_W, min((int) ($entry['w'] ?? BiWidget::MIN_W), BiWidget::GRID_COLUMNS));

        return [
            'grid_w' => $w,
            // x is clamped against the WIDTH, so a wide card can never be placed
            // where it would overhang the right edge of the grid.
            'grid_x' => max(0, min((int) ($entry['x'] ?? 0), BiWidget::GRID_COLUMNS - $w)),
            'grid_y' => max(0, min((int) ($entry['y'] ?? 0), 10_000)),
            'grid_h' => max(BiWidget::MIN_H, min((int) ($entry['h'] ?? BiWidget::MIN_H), BiWidget::MAX_H)),
        ];
    }

    /**
     * @param  array{grid_x: int, grid_y: int, grid_w: int, grid_h: int}  $slot
     */
    private function unchanged(BiWidget $widget, array $slot): bool
    {
        return (int) $widget->grid_x === $slot['grid_x']
            && (int) $widget->grid_y === $slot['grid_y']
            && (int) $widget->grid_w === $slot['grid_w']
            && (int) $widget->grid_h === $slot['grid_h'];
    }
}
