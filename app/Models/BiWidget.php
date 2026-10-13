<?php

namespace App\Models;

use App\Models\Concerns\ScopesActiveTeam;
use App\Models\Concerns\StampsTeam;
use App\Policies\BiWidgetPolicy;
use App\Services\Bi\Dashboard\WidgetType;
use Database\Factories\BiWidgetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One card on a board: a question, a dataset to ask it of, and a shape to draw
 * the answer in.
 *
 * `spec` holds the SAME untrusted array shape `SpecValidator` takes from a
 * request, not a compiled QuerySpec and not SQL. Storing either would freeze an
 * artifact of our own compiler into a row that outlives the deploy that wrote
 * it — and the validator is cheap, while a stale compiled query is a silent
 * wrong answer.
 *
 * It follows that a widget is only as valid as the dataset it points at RIGHT
 * NOW. A rematerialization replaces `bi_columns`, so a saved spec can stop
 * resolving. That is handled by validating on read and rendering a per-widget
 * error (see WidgetRenderer) rather than by validating on write — a board where
 * one card says "this column is gone" is far better than one that refuses to
 * load.
 */
#[Fillable([
    'title', 'type', 'spec', 'filters', 'options',
    'grid_x', 'grid_y', 'grid_w', 'grid_h',
])]
#[UsePolicy(BiWidgetPolicy::class)]
class BiWidget extends Model
{
    /** @use HasFactory<BiWidgetFactory> */
    use HasFactory;

    use ScopesActiveTeam;
    use StampsTeam;

    /** The grid is twelve columns wide, as every gridstack board is. */
    public const GRID_COLUMNS = 12;

    /** Smallest card that can still hold an axis and a legend. */
    public const MIN_W = 2;

    public const MIN_H = 2;

    /** Tallest a single card may be, so one widget cannot bury the rest. */
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

    /**
     * This widget's own filters, as a list.
     *
     * Not keyed by dataset the way a dashboard's are: a widget reads exactly one
     * dataset, so the key would be a constant.
     *
     * @return array<int, array<string, mixed>>
     */
    public function ownFilters(): array
    {
        return array_values($this->filters ?? []);
    }

    /** A presentation option, with its default. */
    public function option(string $key, mixed $default = null): mixed
    {
        return ($this->options ?? [])[$key] ?? $default;
    }

    /**
     * The grid slot, clamped to the board.
     *
     * Clamped on READ as well as on write, because these four numbers are the
     * one part of a widget that a drag can rewrite in bulk — and a row edited by
     * hand, or written by a client that disagrees about the column count, must
     * not be able to push a card off the board or make it 400 rows tall.
     *
     * @return array{x: int, y: int, w: int, h: int}
     */
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

    /**
     * The default slot for a newly added widget.
     *
     * Half-width and placed below everything: gridstack's own auto-placement
     * would float a new card into the first gap, which is startling when the
     * owner has just pressed "add" and is looking at the bottom of the board.
     *
     * @return array{grid_x: int, grid_y: int, grid_w: int, grid_h: int}
     */
    public static function defaultSlot(BiDashboard $dashboard, WidgetType $type): array
    {
        $bottom = (int) $dashboard->widgets()
            ->selectRaw('COALESCE(MAX(grid_y + grid_h), 0) AS bottom')
            ->value('bottom');

        return [
            'grid_x' => 0,
            'grid_y' => $bottom,
            // A KPI is a single number and does not need half the board.
            'grid_w' => $type === WidgetType::Kpi ? 3 : 6,
            'grid_h' => $type === WidgetType::Kpi ? 2 : 4,
        ];
    }
}
