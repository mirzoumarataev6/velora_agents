<?php

namespace App\Models;

use App\Models\Concerns\ScopesActiveTeam;
use App\Models\Concerns\StampsTeam;
use App\Policies\BiWidgetPolicy;
use App\Services\Bi\Dashboard\WidgetType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'title', 'type', 'spec', 'filters', 'options',
    'grid_x', 'grid_y', 'grid_w', 'grid_h',
])]
#[UsePolicy(BiWidgetPolicy::class)]
class BiWidget extends Model
{
    use HasFactory;
    use ScopesActiveTeam;
    use StampsTeam;

    public const GRID_COLUMNS = 12;

    public const MIN_W = 2;

    public const MIN_H = 2;

    public const MAX_H = 24;

    protected function casts(): array
    {
        return [
            'type' => WidgetType::class,
            'spec' => 'array',
            'filters' => 'array',
            'options' => 'array',
            'grid_x' => 'integer',
            'grid_y' => 'integer',
            'grid_w' => 'integer',
            'grid_h' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function dashboard(): BelongsTo
    {
        return $this->belongsTo(BiDashboard::class, 'bi_dashboard_id');
    }

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(BiDataset::class, 'bi_dataset_id');
    }

    public function ownFilters(): array
    {
        return array_values($this->filters ?? []);
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return ($this->options ?? [])[$key] ?? $default;
    }

    public function slot(): array
    {
        $w = max(self::MIN_W, min((int) $this->grid_w, self::GRID_COLUMNS));
        $x = max(0, min((int) $this->grid_x, self::GRID_COLUMNS - $w));

        return [
            'x' => $x,
            'y' => max(0, (int) $this->grid_y),
            'w' => $w,
            'h' => max(self::MIN_H, min((int) $this->grid_h, self::MAX_H)),
        ];
    }

    public static function defaultSlot(BiDashboard $dashboard, WidgetType $type): array
    {
        $bottom = (int) $dashboard->widgets()
            ->selectRaw('COALESCE(MAX(grid_y + grid_h), 0) AS bottom')
            ->value('bottom');

        return [
            'grid_x' => 0,
            'grid_y' => $bottom,

            'grid_w' => $type === WidgetType::Kpi ? 3 : 6,
            'grid_h' => $type === WidgetType::Kpi ? 2 : 4,
        ];
    }
}
