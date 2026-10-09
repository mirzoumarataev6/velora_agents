<?php

namespace Database\Factories;

use App\Models\BiDashboard;
use App\Models\BiWidget;
use App\Services\Bi\Dashboard\WidgetType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BiWidget>
 */
class BiWidgetFactory extends Factory
{
    public function definition(): array
    {
        return [
            // Not defaulted to the dashboard's owner, for the same reason
            // BiDatasetFactory does not default to its source's: a test must be
            // able to build the cross-tenant mismatch on purpose.
            'user_id' => fn (array $attributes) => BiDashboard::find($attributes['bi_dashboard_id'])?->user_id,
            'bi_dashboard_id' => BiDashboard::factory(),
            'title' => 'Выручка по регионам',
            'type' => WidgetType::Column,
            'spec' => [
                'dimensions' => [['column' => 'c1']],
                'measures' => [['aggregate' => 'sum', 'column' => 'c2']],
            ],
            'filters' => null,
            'options' => null,
            'grid_x' => 0,
            'grid_y' => 0,
            'grid_w' => 6,
            'grid_h' => 4,
        ];
    }

    public function type(WidgetType $type): static
    {
        return $this->state(fn () => ['type' => $type]);
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    public function spec(array $spec): static
    {
        return $this->state(fn () => ['spec' => $spec]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $filters
     */
    public function filtering(array $filters): static
    {
        return $this->state(fn () => ['filters' => $filters]);
    }

    public function at(int $x, int $y, int $w = 6, int $h = 4): static
    {
        return $this->state(fn () => ['grid_x' => $x, 'grid_y' => $y, 'grid_w' => $w, 'grid_h' => $h]);
    }
}
