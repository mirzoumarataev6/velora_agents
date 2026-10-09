@php
    /**
     * Shared arithmetic and formatting for the BI widgets.
     *
     * The sibling of partials/chart-helpers.blade.php, and separate from it on
     * purpose: that file serves the product's own fixed dashboards, where the
     * shape of the data is known at author time. These serve charts an OWNER
     * composed, where the column types, the bucket grains and the number of
     * series are all discovered at render time — so almost every function here
     * takes a type or a grain that the other file never needs.
     *
     * Guarded and idempotent, like its sibling: a board includes it once per
     * widget and must not redeclare anything.
     */
    if (! function_exists('velora_series_class')) {
        /**
         * The class carrying a series' colour.
         *
         * Zero-indexed in, 1-indexed out, because the tokens are authored as
         * `series-1`…`series-6` and an off-by-one here would silently paint
         * every chart's first series in the second slot's hue.
         *
         * Past the last slot this returns the neutral rather than wrapping
         * around. That is the whole rule: a seventh category is "Other", never
         * a second series in the brand blue. Callers that can aggregate the
         * remainder (the donut) do so; callers that cannot simply draw it grey,
         * which at least does not claim it is the same thing as series one.
         */
        function velora_series_class(int $index): string
        {
            $slots = \App\Services\Bi\Dashboard\WidgetType::SERIES_SLOTS;

            return $index < $slots ? 'series-'.($index + 1) : 'series-other';
        }
    }

    if (! function_exists('velora_bi_number')) {
        /**
         * A measure, formatted for a human.
         *
         * Thin spaces as the thousands separator and a comma for the decimal:
         * Russian is this product's primary locale and that is its convention,
         * and a dashboard that writes 1,234.56 to a Tajik shop owner reads as
         * software written for somewhere else.
         *
         * Large numbers are abbreviated — 1,2 млн rather than 1 234 567 —
         * because the alternative on a 300px-wide card is either a clipped
         * number or a 7pt font. The full value is always in the hover title, so
         * nothing is actually hidden.
         */
        function velora_bi_number(mixed $value, bool $abbreviate = false): string
        {
            if ($value === null || $value === '') {
                return '—';
            }

            if (! is_numeric($value)) {
                return (string) $value;
            }

            $number = (float) $value;

            if ($abbreviate && abs($number) >= 1000) {
                foreach ([[1e9, 'млрд'], [1e6, 'млн'], [1e3, 'тыс']] as [$scale, $suffix]) {
                    if (abs($number) >= $scale) {
                        $scaled = $number / $scale;

                        // One decimal below 10 (9,4 млн), none above (94 млн):
                        // the extra digit stops mattering as the number grows,
                        // and dropping it keeps every label the same width.
                        $decimals = abs($scaled) < 10 ? 1 : 0;
                        $text = number_format($scaled, $decimals, ',', "\u{202f}");

                        // Trim a trailing ",0" — but ONLY when there is a
                        // decimal part to trim. A bare rtrim of '0' turns
                        // "150" into "15", which is how this first shipped
                        // reporting 150 000 somoni as "15 тыс".
                        if ($decimals > 0) {
                            $text = rtrim(rtrim($text, '0'), ',');
                        }

                        return $text.' '.$suffix;
                    }
                }
            }

            // Whole numbers keep no decimals; a fractional value keeps two.
            // Deciding from the VALUE rather than the column type is deliberate:
            // a decimal column holding whole somoni should not render ,00 on
            // every row.
            $decimals = floor($number) === $number ? 0 : 2;

            return number_format($number, $decimals, ',', "\u{202f}");
        }
    }

    if (! function_exists('velora_bi_axis_label')) {
        /**
         * A dimension value, shortened for an axis.
         *
         * Date buckets are re-formatted from the ISO string the compiler
         * produced — `2026-03-01` on a month axis reads as "мар 26", which is
         * what a reader needs, while the full value stays in the tooltip.
         * Quarters already arrive human-readable (`2026-Q1`) and pass through.
         *
         * Everything else is the owner's own text and is TRUNCATED, never
         * reformatted: a category called "ООО «Ромашка», Душанбе" is that, and
         * silently rewriting it would be the chart lying about its own data.
         */
        function velora_bi_axis_label(mixed $value, ?string $grain = null, int $max = 18): string
        {
            if ($value === null || $value === '') {
                return __('bi.widgets.blank');
            }

            $text = (string) $value;

            if ($grain !== null && $grain !== 'quarter') {
                $months = ['янв', 'фев', 'мар', 'апр', 'мая', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек'];

                if ($grain === 'year') {
                    return $text;
                }

                if (preg_match('/^(\d{4})-(\d{2})(?:-(\d{2}))?$/', $text, $m) === 1) {
                    $month = $months[((int) $m[2]) - 1] ?? $m[2];

                    return match ($grain) {
                        'month' => $month.' '.substr($m[1], 2),
                        default => ltrim($m[3] ?? '', '0').' '.$month,
                    };
                }
            }

            return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1).'…' : $text;
        }
    }

    if (! function_exists('velora_bi_ceiling')) {
        /**
         * A "nice" axis ceiling.
         *
         * THE MIDPOINT IS WHAT THIS IS ACTUALLY SOLVING FOR. Every chart here
         * draws three gridlines — 0, half, max — so the ceiling must be twice
         * a round number, or the middle label reads 32,5 while the line sits
         * somewhere a reader cannot verify.
         *
         * So the HALF is rounded up to a nice number and doubled, rather than
         * the ceiling being rounded up directly. The direct version is the
         * obvious one and it wastes the card: rounding 105 000 up to the next
         * nice number gives 200 000, and the chart then draws its data in the
         * bottom half of the plot with the top half empty. Rounding the half
         * (52 500 → 60 000) gives a ceiling of 120 000, a midpoint of 60 000,
         * and a chart that uses its height.
         *
         * The nice set is deliberately denser than the usual 1/2/5: at three
         * gridlines a coarse set is exactly what produces the wasted half.
         */
        function velora_bi_ceiling(array $values): float
        {
            $peak = 0.0;

            foreach ($values as $value) {
                $peak = max($peak, abs((float) $value));
            }

            if ($peak <= 0.0) {
                return 2.0;
            }

            $half = $peak / 2;
            $magnitude = 10 ** floor(log10($half));
            $scaled = $half / $magnitude;

            foreach ([1.0, 1.5, 2.0, 2.5, 3.0, 4.0, 5.0, 6.0, 8.0, 10.0] as $nice) {
                if ($scaled <= $nice) {
                    return 2 * $nice * $magnitude;
                }
            }

            return 2 * 10 * $magnitude;
        }
    }

    if (! function_exists('velora_bi_arc')) {
        /**
         * One donut slice, as an arc path on a circle of radius $r.
         *
         * Drawn as a STROKED arc rather than a filled wedge: a wedge needs two
         * radii and a fill rule, and the inner edge of every slice then has to
         * be mitred against its neighbours. A stroke with a width gives the
         * same ring for one `A` command, and the hole comes free.
         *
         * Angles start at twelve o'clock, which is where a reader starts.
         */
        function velora_bi_arc(float $cx, float $cy, float $r, float $startFraction, float $endFraction): string
        {
            // A full circle cannot be drawn as a single arc — start and end
            // coincide and the renderer draws nothing at all. Two half arcs is
            // the standard way out, and the single-category donut is common
            // enough (one region, one product) to be worth handling.
            if ($endFraction - $startFraction >= 0.9999) {
                $top = $cy - $r;
                $bottom = $cy + $r;

                return "M{$cx},{$top}A{$r},{$r} 0 1 1 {$cx},{$bottom}A{$r},{$r} 0 1 1 {$cx},{$top}";
            }

            $start = ($startFraction * 2 - 0.5) * M_PI;
            $end = ($endFraction * 2 - 0.5) * M_PI;

            $x1 = round($cx + $r * cos($start), 3);
            $y1 = round($cy + $r * sin($start), 3);
            $x2 = round($cx + $r * cos($end), 3);
            $y2 = round($cy + $r * sin($end), 3);

            $large = ($endFraction - $startFraction) > 0.5 ? 1 : 0;

            return "M{$x1},{$y1}A{$r},{$r} 0 {$large} 1 {$x2},{$y2}";
        }
    }
@endphp
