@props(['widget', 'result', 'fill' => false])

@include('partials.chart-helpers')
@include('partials.widget-helpers')

@php
    /* Line and area share this file; `fill` is the only difference.

       They are one component because they are one chart with one decision
       changed — whether the region under the curve is painted. Keeping them
       apart would mean two copies of the multi-series scale arithmetic, and
       the copies would drift.

       GEOMETRY follows components/area-chart.blade.php exactly, including the
       reason: the SVG stretches with `preserveAspectRatio="none"` so the curve
       fills whatever width the card has, and the axis labels therefore live in
       HTML AROUND the SVG rather than as <text> inside it — stretched type is
       the clearest tell that a chart was drawn rather than designed. Strokes
       survive because they are pinned with `vector-effect: non-scaling-stroke`
       in the shared `.chart-line` skin.

       This is the opposite choice from widget-column, and the note there
       explains why a bar chart cannot make it. */
    $dimensions = array_values(array_filter($result->columns, fn ($c) => $c['kind'] === 'dimension'));
    $measures = array_values(array_filter($result->columns, fn ($c) => $c['kind'] === 'measure'));

    $dimension = $dimensions[0] ?? null;
    $rows = $result->rows;
    $count = count($rows);

    $all = [];
    foreach ($rows as $row) {
        foreach ($measures as $measure) {
            $all[] = (float) ($row[$measure['alias']] ?? 0);
        }
    }

    /* ONE SCALE FOR EVERY SERIES, always. Giving a second measure its own axis
       is the single most misleading thing a line chart can do — two y-scales
       let the author place any two lines in any relationship they like, and the
       reader has no way to see it. Two measures of wildly different magnitude
       belong on two cards, and the owner can make two. */
    $ceiling = velora_bi_ceiling($all);

    $w = 1000.0;
    $h = 220.0;
    $gridLines = [0.0, 0.5, 1.0];

    $seriesPaths = [];
    foreach ($measures as $i => $measure) {
        $values = array_map(fn ($row) => (float) ($row[$measure['alias']] ?? 0), $rows);
        $points = velora_chart_points($values, $w, $h, $ceiling, 6.0);

        $seriesPaths[] = [
            'class' => velora_series_class($i),
            'line' => velora_chart_line($points),
            'area' => velora_chart_area($points, $h),
            'points' => $points,
            'values' => $values,
            'label' => ($measure['label'] ?: __('bi.aggregates.'.$measure['aggregate'])),
            'gradient' => 'wfill-'.$widget->getKey().'-'.$i,
        ];
    }

    $labels = array_map(
        fn ($row) => velora_bi_axis_label($row[$dimension['alias'] ?? ''] ?? null, $dimension['grain'] ?? null, 14),
        $rows
    );

    $labelEvery = max(1, (int) ceil($count / 7));

    $legend = [];
    foreach ($seriesPaths as $i => $series) {
        $legend[] = ['alias' => 's'.$i, 'class' => $series['class'], 'label' => $series['label']];
    }
@endphp

<div class="flex h-full flex-col">
    <div class="flex min-h-0 flex-1 gap-2">
        {{-- The y-axis as HTML.

             `justify-between` on a column the exact height of the plot rather
             than absolute positioning with a -50% translate: the translated
             version put half the top label above the card and clipped it, which
             is what the first render of this component actually did. Flex
             alignment keeps every label fully inside the box. The `pb-6`
             matches the x-axis label row below the SVG so the bottom label
             still lands on the baseline. --}}
        <div class="flex w-14 shrink-0 flex-col justify-between pb-6 text-right" aria-hidden="true">
            @foreach (array_reverse($gridLines) as $fraction)
                <span class="chart-axis-label whitespace-nowrap leading-none">{{ velora_bi_number($ceiling * $fraction, true) }}</span>
            @endforeach
        </div>

        <div class="flex min-w-0 flex-1 flex-col">
            <svg class="chart chart-animate w-full flex-1" viewBox="0 0 {{ $w }} {{ $h }}"
                preserveAspectRatio="none" role="img" aria-label="{{ $widget->title }}">
                @if ($fill)
                    <defs>
                        @foreach ($seriesPaths as $series)
                            {{-- Each series needs its OWN gradient id, or every
                                 series after the first inherits the first one's
                                 fill. The id carries the widget key as well, so
                                 two cards on one board cannot collide either. --}}
                            <linearGradient id="{{ $series['gradient'] }}" x1="0" y1="0" x2="0" y2="1"
                                class="{{ $series['class'] }}">
                                <stop offset="0%" stop-color="currentColor" stop-opacity="0.22" />
                                <stop offset="100%" stop-color="currentColor" stop-opacity="0.01" />
                            </linearGradient>
                        @endforeach
                    </defs>
                @endif

                @foreach ($gridLines as $fraction)
                    @php $y = round($h * (1 - $fraction), 2); @endphp
                    <line class="chart-grid" x1="0" y1="{{ $y }}" x2="{{ $w }}" y2="{{ $y }}" />
                @endforeach

                @foreach ($seriesPaths as $series)
                    <g class="{{ $series['class'] }}">
                        @if ($fill)
                            <path class="chart-area" fill="url(#{{ $series['gradient'] }})" d="{{ $series['area'] }}" />
                        @endif
                        <path class="chart-line" pathLength="1" d="{{ $series['line'] }}" />

                        {{-- Markers only when they can breathe. Past ten points
                             they merge into a dotted line and stop carrying
                             information. --}}
                        @if ($count <= 10)
                            @foreach ($series['points'] as $point)
                                <circle class="chart-dot" cx="{{ $point[0] }}" cy="{{ $point[1] }}" r="4" />
                            @endforeach
                        @endif
                    </g>
                @endforeach

                {{-- The hover layer: one full-height invisible column per x
                     position, carrying every series' value at that point. A 2px
                     line is impossible to aim at, and a per-series target would
                     make the reader hunt for the one they want. --}}
                @php $colW = $count > 1 ? $w / ($count - 1) : $w; @endphp
                @foreach ($rows as $i => $row)
                    @php $x = $count > 1 ? $i * $colW : $w / 2; @endphp
                    <rect class="chart-hit" x="{{ round($x - $colW / 2, 2) }}" y="0"
                        width="{{ round($colW, 2) }}" height="{{ $h }}">
                        <title>{{ $labels[$i] }}@foreach ($seriesPaths as $series)
 · {{ $series['label'] }}: {{ velora_bi_number($series['values'][$i] ?? 0) }}@endforeach</title>
                    </rect>
                @endforeach
            </svg>

            <div class="relative mt-2 h-4 shrink-0" aria-hidden="true">
                @foreach ($labels as $i => $label)
                    @if ($i % $labelEvery === 0 || $i === $count - 1)
                        @php $fraction = $count > 1 ? $i / ($count - 1) : 0.5; @endphp
                        <span class="chart-axis-label absolute whitespace-nowrap"
                            style="left: {{ round($fraction * 100, 2) }}%;
                                   transform: translateX({{ $i === 0 ? '0' : ($i === $count - 1 ? '-100%' : '-50%') }})">{{ $label }}</span>
                    @endif
                @endforeach
            </div>
        </div>
    </div>

    <x-bi.widget-legend :entries="$legend" />
</div>
