<?php

namespace App\Services\Bi\Dashboard;

use App\Models\BiDashboard;
use App\Models\BiWidget;
use Illuminate\Support\Facades\DB;

class LayoutUpdater
{
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

        $widgets = $dashboard->widgets()->whereIn('id', array_keys($byId))->get();

        if ($widgets->isEmpty()) {
            return 0;
        }

        return DB::transaction(function () use ($widgets, $byId) {
            $moved = 0;

            foreach ($widgets as $widget) {
                $slot = $this->clamp($byId[$widget->getKey()]);

                if ($this->unchanged($widget, $slot)) {
                    continue;
                }

                $widget->timestamps = false;
                $widget->forceFill($slot)->saveQuietly();
                $widget->timestamps = true;

                $moved++;
            }

            return $moved;
        });
    }

    private function clamp(array $entry): array
    {
        $w = max(BiWidget::MIN_W, min((int) ($entry['w'] ?? BiWidget::MIN_W), BiWidget::GRID_COLUMNS));

        return [
            'grid_w' => $w,

            'grid_x' => max(0, min((int) ($entry['x'] ?? 0), BiWidget::GRID_COLUMNS - $w)),
            'grid_y' => max(0, min((int) ($entry['y'] ?? 0), 10_000)),
            'grid_h' => max(BiWidget::MIN_H, min((int) ($entry['h'] ?? BiWidget::MIN_H), BiWidget::MAX_H)),
        ];
    }

    private function unchanged(BiWidget $widget, array $slot): bool
    {
        return (int) $widget->grid_x === $slot['grid_x']
            && (int) $widget->grid_y === $slot['grid_y']
            && (int) $widget->grid_w === $slot['grid_w']
            && (int) $widget->grid_h === $slot['grid_h'];
    }
}
