@php
    /**
     * Path arithmetic shared by every chart in the product.
     *
     * Lives in a Blade partial, and is `@include`d by the views that need it,
     * because this is presentation and nothing else: it turns a list of numbers
     * into an SVG `d` string. Nothing here knows what a lead or a message is,
     * and putting it in app/ would only move view code somewhere views cannot
     * see it. Include is idempotent — the guards below mean a page can pull it
     * in from three partials without redeclaring anything.
     *
     * Why smoothing at all: the brief asks for a smooth line/area chart, and a
     * polyline through daily counts reads as jagged noise at this size. A
     * Catmull-Rom spline converted to cubic béziers passes through every real
     * data point (so no value is ever misdrawn) while rounding the corners
     * between them.
     */
    if (! function_exists('velora_chart_points')) {
        /**
         * Map values onto an SVG box. Y is flipped — SVG's origin is top-left,
         * and a chart's is bottom-left.
         *
         * `$max` is passed in rather than derived so several charts on one card
         * can share a scale. A flat series (every value equal, including all
         * zeroes) would divide by zero, so it is pinned to the baseline
         * instead — a flat line is the honest picture of flat data.
         */
        function velora_chart_points(array $values, float $w, float $h, ?float $max = null, float $pad = 0.0): array
        {
            $count = count($values);

            if ($count === 0) {
                return [];
            }

            $max ??= (float) max($values);
            $max = max($max, 0.0);
            $usable = $h - $pad * 2;

            if ($count === 1) {
                return [[$w / 2, $max > 0 ? $pad + $usable * (1 - $values[0] / $max) : $h - $pad]];
            }

            $step = $w / ($count - 1);
            $points = [];

            foreach (array_values($values) as $i => $value) {
                $y = $max > 0
                    ? $pad + $usable * (1 - (float) $value / $max)
                    : $h - $pad;
                $points[] = [round($i * $step, 2), round($y, 2)];
            }

            return $points;
        }
    }

    if (! function_exists('velora_chart_line')) {
        /**
         * A smooth open path through the points — the series itself.
         *
         * Catmull-Rom with tension 1/6: each segment's control points are set
         * from the neighbouring points, which keeps the curve passing exactly
         * through the data. The endpoints duplicate themselves so the first and
         * last segments have a neighbour to read.
         */
        function velora_chart_line(array $points): string
        {
            $n = count($points);

            if ($n === 0) {
                return '';
            }
            if ($n === 1) {
                return 'M'.$points[0][0].','.$points[0][1];
            }

            $d = 'M'.$points[0][0].','.$points[0][1];

            for ($i = 0; $i < $n - 1; $i++) {
                $p0 = $points[max(0, $i - 1)];
                $p1 = $points[$i];
                $p2 = $points[$i + 1];
                $p3 = $points[min($n - 1, $i + 2)];

                $c1x = round($p1[0] + ($p2[0] - $p0[0]) / 6, 2);
                $c1y = round($p1[1] + ($p2[1] - $p0[1]) / 6, 2);
                $c2x = round($p2[0] - ($p3[0] - $p1[0]) / 6, 2);
                $c2y = round($p2[1] - ($p3[1] - $p1[1]) / 6, 2);

                $d .= "C{$c1x},{$c1y} {$c2x},{$c2y} {$p2[0]},{$p2[1]}";
            }

            return $d;
        }
    }

    if (! function_exists('velora_chart_area')) {
        /**
         * The same curve, closed down to the baseline — the soft fill under it.
         */
        function velora_chart_area(array $points, float $baseline): string
        {
            if ($points === []) {
                return '';
            }

            $last = $points[count($points) - 1];
            $first = $points[0];

            return velora_chart_line($points)
                ."L{$last[0]},{$baseline}"
                ."L{$first[0]},{$baseline}Z";
        }
    }

    if (! function_exists('velora_trend')) {
        /**
         * Period-over-period movement, computed from a daily series the payload
         * already carries: the second half of the window against the first.
         *
         * Deliberately NOT a new query. The dashboard is handed `range` days of
         * daily numbers, and "the last N days vs the N before them" is the
         * comparison an owner actually reads off a 7- or 30-day chart. Halving
         * the window in the view keeps the whole trend pill derivable from the
         * data already on the page.
         *
         * Returns null when there is nothing honest to say — an empty series,
         * or a previous half of zero, where "+∞%" would be the arithmetic
         * answer and a lie as a headline.
         *
         * @return array{direction: 'up'|'down'|'flat', percent: int}|null
         */
        function velora_trend(array $values): ?array
        {
            $values = array_values(array_map('floatval', $values));
            $count = count($values);

            if ($count < 4) {
                return null;
            }

            $half = intdiv($count, 2);
            $previous = array_sum(array_slice($values, 0, $half));
            $current = array_sum(array_slice($values, -$half));

            if ($previous <= 0.0) {
                return null;
            }

            $change = ($current - $previous) / $previous * 100;
            $rounded = (int) round($change);

            return [
                // A sub-1% move is noise, and an arrow on it is a false signal.
                'direction' => $rounded > 0 ? 'up' : ($rounded < 0 ? 'down' : 'flat'),
                'percent' => abs($rounded),
            ];
        }
    }
@endphp
