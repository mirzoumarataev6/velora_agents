<?php

namespace App\Services\Bi\Dashboard;

/**
 * The closed set of things a widget can be drawn as.
 *
 * Seven, and each one earns its place by answering a question the others answer
 * badly. Every one is hand-rolled SVG or plain HTML — this product ships no
 * chart library, which is a deliberate and load-bearing choice (see CLAUDE.md),
 * so each type here is a Blade component someone has to maintain. That cost is
 * what keeps the list short.
 *
 * Deliberately absent: scatter (needs two measures on two axes and a different
 * spec shape entirely), radar (unreadable past four dimensions, and flattering
 * in a way that misleads), gauge (a KPI with decoration), 3D anything.
 *
 * The SHAPE RULES below are the useful part. A donut with a time dimension and
 * no limit is a hundred slices; a line chart with no dimension is a single
 * point. Rather than let the editor offer a combination that renders as
 * nonsense, each type states what it needs, and WidgetValidator refuses the
 * rest with a classified code.
 */
enum WidgetType: string
{
    /**
     * How many categorical hues exist, and therefore how many series or slices
     * can be drawn before the rest becomes "Other".
     *
     * Six is not a round number picked for tidiness: it is the largest set that
     * cleared the colour-vision separation check in BOTH themes. The dark
     * band is the binding constraint. See tailwind.config.js.
     */
    public const SERIES_SLOTS = 6;

    case Line = 'line';
    case Area = 'area';
    case Bar = 'bar';
    case Column = 'column';
    case Donut = 'donut';
    case Table = 'table';
    case Kpi = 'kpi';

    /** Dimensions this type needs: [min, max]. */
    public function dimensionRange(): array
    {
        return match ($this) {
            // A single number. A dimension would make it several numbers, which
            // is a different widget.
            self::Kpi => [0, 0],
            // One category axis. A second would need grouping or stacking, which
            // is a real feature and not one this phase ships — refusing it is
            // better than silently drawing only the first.
            self::Line, self::Area, self::Donut => [1, 1],
            // A second dimension names the series in a grouped/stacked bar.
            // It stays in the validated QuerySpec; no chart builds SQL itself.
            self::Bar, self::Column => [1, 2],
            // A table is the escape hatch: whatever the spec allows.
            self::Table => [0, QueryFieldLimit::MAX],
        };
    }

    /** Measures this type needs: [min, max]. */
    public function measureRange(): array
    {
        return match ($this) {
            self::Kpi => [1, 1],
            // One slice value. Two would be two pies.
            self::Donut => [1, 1],
            // Several series on one axis is the whole point of a line chart.
            // Four, not six: a line chart with six overlapping series is a
            // thicket even when every colour is distinguishable. The palette
            // could carry more; the shape cannot.
            self::Line, self::Area, self::Bar, self::Column => [1, 4],
            self::Table => [0, QueryFieldLimit::MAX],
        };
    }

    /**
     * Whether this type reads better with its categories ranked by value.
     *
     * Applied as a DEFAULT sort when the owner has not chosen one, never as an
     * override. A bar chart of regions is almost always wanted biggest-first; a
     * line chart over months must stay in date order or it is not a line.
     */
    public function ranksByDefault(): bool
    {
        return $this === self::Bar || $this === self::Column || $this === self::Donut;
    }

    /** Whether the x-axis is a sequence, so its order carries meaning. */
    public function isSequential(): bool
    {
        return $this === self::Line || $this === self::Area;
    }

    /**
     * How many categories are legible in this shape.
     *
     * Used as the default `limit` when a spec does not state one. These are
     * presentation numbers, not safety limits — `QuerySpec::maxRows()` is the
     * ceiling — and they exist because the honest answer to "show revenue by
     * product" for a 4,000-product catalog is the top 20 and a note, not 4,000
     * slivers one pixel wide.
     */
    public function defaultLimit(): int
    {
        return match ($this) {
            self::Kpi => 1,
            // Six, because six is how many categorical hues this product has
            // that a colourblind reader can still tell apart — see the `series`
            // note in tailwind.config.js. The donut asks for one more than it
            // can colour (SERIES_SLOTS + 1) so it can tell the difference
            // between "exactly six categories" and "more than six", and fold
            // the remainder into a single neutral "Other" slice rather than
            // reusing a hue. Cycling the palette would draw two different
            // regions in the same blue, which is not a crowded chart but a
            // wrong one.
            self::Donut => self::SERIES_SLOTS + 1,
            self::Bar, self::Column => 20,
            self::Line, self::Area => 200,
            self::Table => 50,
        };
    }

    /** Whether the owner's "show the legend" toggle applies. */
    public function hasLegend(): bool
    {
        return $this !== self::Kpi && $this !== self::Table;
    }

    /** The Blade component that draws it. */
    public function component(): string
    {
        return 'bi.widget-'.$this->value;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
