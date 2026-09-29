@props([
    // [['label' => '23.09', 'value' => 12], …] — oldest first.
    'points' => [],
    // Rendered in each hover tooltip after the value; pass null for none.
    'unit' => null,
    'height' => 200,
])

@include('partials.chart-helpers')

@php
    $rows = array_values($points);
    $values = array_map(fn ($row) => (float) ($row['value'] ?? 0), $rows);
    $labels = array_map(fn ($row) => (string) ($row['label'] ?? ''), $rows);
    $count = count($rows);

    /* THE GEOMETRY, and the one non-obvious decision in this file.

       The SVG stretches to the card's width with `preserveAspectRatio="none"`,
       because a chart has to fill its card and its aspect ratio is whatever the
       layout gives it. That stretch applies to EVERYTHING inside the SVG —
       which is why the axis labels are HTML sitting around the SVG rather than
       <text> inside it. Stretched type is the single most obvious tell that a
       chart was drawn rather than designed, and at a ~1.5x horizontal scale it
       is unmissable.

       What stays inside the SVG is only what survives being stretched: fills
       (which have no intrinsic proportion) and strokes (which are pinned with
       `vector-effect: non-scaling-stroke`, so the line keeps its 2px no matter
       how wide the card is). */
    $w = 1000.0;
    $h = 200.0;

    /* Round the ceiling up to something a person would choose, so the axis
       reads 0 / 35 / 70 rather than 0 / 32.5 / 65.

       The step has to be EVEN, because the middle gridline is the ceiling
       halved and a reader should not be shown "32.5" — or, worse, a silently
       rounded "33" that does not sit where the line is drawn. Rounding up to a
       whole multiple of the magnitude guarantees the midpoint is a whole
       number too. A flat-zero series still gets a scale of 2, so the baseline
       and its midpoint both draw. */
    $peak = $values === [] ? 0.0 : max($values);
    $nice = max(2.0, (float) $peak);
    $magnitude = max(2.0, 10 ** floor(log10($nice)));
    $ceiling = ceil($nice / $magnitude) * $magnitude;

    // 2 units of padding keeps the end dots from being clipped by the viewBox.
    $points2d = velora_chart_points($values, $w, $h, $ceiling, 4.0);

    /* Three gridlines — 0, half, full. Four or more turns the card into graph
       paper, which is the opposite of airy. */
    $gridLines = [0.0, 0.5, 1.0];

    /* 30 daily labels will not fit across a card, so only every nth is drawn.
       The first and last always are: they are what tells a reader the window. */
    $labelEvery = max(1, (int) ceil($count / 8));

    // Every gradient on a page needs its own id, or the second chart inherits
    // the first one's fill.
    $gradientId = 'area-'.\Illuminate\Support\Str::random(6);
@endphp

@if ($count >= 2)
    {{-- `text-brand` on the WRAPPER, not on a <g> inside the SVG: the gradient
         stops below use `currentColor`, and a stop resolves currentColor
         against its own inherited colour — the <defs> block's, not that of
         whatever references the gradient. Setting it on an inner <g> is why
         the fill came out invisible the first time. --}}
    <div class="text-brand dark:text-brand-light">
        <div class="flex gap-2">
            {{-- The y-axis, as HTML. `justify-between` on a box the exact
                 height of the plot puts each label on its gridline without any
                 arithmetic, and `-translate-y-1/2` centres it across the line
                 rather than hanging it below. --}}
            <div class="relative w-8 shrink-0" style="height: {{ $height }}px" aria-hidden="true">
                @foreach (array_reverse($gridLines) as $fraction)
                    <span class="chart-axis-label absolute right-0 -translate-y-1/2"
                        style="top: {{ (1 - $fraction) * 100 }}%">{{ (int) round($ceiling * $fraction) }}</span>
                @endforeach
            </div>

            <div class="min-w-0 flex-1">
                <svg class="chart chart-animate"
                    style="height: {{ $height }}px"
                    viewBox="0 0 {{ $w }} {{ $h }}"
                    preserveAspectRatio="none"
                    role="img"
                    {{ $attributes }}>
                    <defs>
                        <linearGradient id="{{ $gradientId }}" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stop-color="currentColor" stop-opacity="0.2" />
                            <stop offset="100%" stop-color="currentColor" stop-opacity="0.01" />
                        </linearGradient>
                    </defs>

                    {{-- Gridlines first, so the series sits over them. --}}
                    @foreach ($gridLines as $fraction)
                        @php $y = round($h * (1 - $fraction), 2); @endphp
                        <line class="chart-grid" x1="0" y1="{{ $y }}" x2="{{ $w }}" y2="{{ $y }}" />
                    @endforeach

                    <path class="chart-area" fill="url(#{{ $gradientId }})"
                        d="{{ velora_chart_area($points2d, $h) }}" />
                    {{-- pathLength="1" normalises the draw-in animation: one
                         dash length works for a 7-point and a 30-point chart
                         alike, because both paths are declared to be 1 long. --}}
                    <path class="chart-line" pathLength="1" d="{{ velora_chart_line($points2d) }}" />

                    {{-- Dots only when they can breathe. At 30 points they merge
                         into a dotted line and stop meaning anything. --}}
                    @if ($count <= 10)
                        @foreach ($points2d as $point)
                            <circle class="chart-dot" cx="{{ $point[0] }}" cy="{{ $point[1] }}" r="4" />
                        @endforeach
                    @endif

                    {{-- The hover targets: a full-height invisible column per
                         point, because a 2px line is impossible to aim at.
                         <title> gives the native tooltip and doubles as the
                         accessible description of each value. --}}
                    @php $colW = $count > 1 ? $w / ($count - 1) : $w; @endphp
                    @foreach ($points2d as $i => $point)
                        <rect class="chart-hit"
                            x="{{ round($point[0] - $colW / 2, 2) }}" y="0"
                            width="{{ round($colW, 2) }}" height="{{ $h }}">
                            <title>{{ $labels[$i] }} — {{ (int) $values[$i] }}{{ $unit ? ' '.$unit : '' }}</title>
                        </rect>
                    @endforeach
                </svg>

                {{-- The x-axis, also HTML. Each label is absolutely positioned
                     at its point's own x fraction, so it stays under its data
                     point at every card width. --}}
                <div class="relative mt-2 h-4" aria-hidden="true">
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
    </div>
@endif
