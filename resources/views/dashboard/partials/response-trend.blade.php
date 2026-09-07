{{--
    Average reply time per day.

    A line, not bars: the question this answers is "which way is it going",
    and a run of bars invites reading each day on its own. Drawn as an inline
    SVG polyline rather than divs — a line is the one chart shape divs cannot
    do honestly, and this needs no library either way.

    Days with no measured reply are a GAP in the line, not a zero. A quiet
    Sunday is not an instant one, and joining across it would draw a dive that
    never happened.

    Expects: $metrics.
--}}
@php
    $trend = $metrics['response_trend'];
    $series = $trend['series'];
    $peak = $trend['peak'];

    $format = function (?float $ms): ?string {
        if ($ms === null) {
            return null;
        }

        return $ms >= 1000 ? round($ms / 1000, 1).' с' : round($ms).' мс';
    };

    // The drawing area's height, in rem — the same way every other chart on
    // this dashboard is constrained (see $trackRem in the messages chart and in
    // the ratings trend). It used to be `flex-1` with a min-height, which let
    // the plot absorb every spare pixel of the grid row; since the row is as
    // tall as the heatmap beside it, a two-point line was being stretched over
    // the best part of a screen. A chart's height should come from what it is,
    // not from what happens to sit next to it.
    $chartRem = 7;

    // Points in a 0-100 viewBox, so the SVG scales to whatever width the card
    // ends up with. Only measured days become points.
    $count = max(1, count($series) - 1);
    $points = [];

    foreach ($series as $index => $day) {
        if ($day['ms'] === null) {
            continue;
        }

        $points[] = [
            'x' => round($index / $count * 100, 2),
            // Inverted: SVG y grows downward, and a faster day should sit
            // higher. 6% of headroom keeps the peak off the top edge.
            'y' => round(94 - ($day['ms'] / $peak * 88), 2),
            'day' => $day,
        ];
    }

    $polyline = implode(' ', array_map(fn ($p) => $p['x'].','.$p['y'], $points));
@endphp

<div class="flex h-full flex-col rounded-2xl border border-black/10 bg-white p-5 dark:border-white/10 dark:bg-white/5">
    <h2 class="text-base font-semibold">{{ __('dashboard.response_trend.title') }}</h2>
    <p class="mt-1 text-xs text-muted dark:text-night-muted">{{ __('dashboard.response_trend.description') }}</p>

    @if (! $trend['has_data'])
        <p class="mt-8 flex-1 text-center text-sm text-muted dark:text-night-muted">{{ __('dashboard.response_trend.empty') }}</p>
    @else
        <div class="relative mt-5" style="height: {{ $chartRem }}rem">
            <svg class="h-full w-full overflow-visible" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
                <defs>
                    <linearGradient id="trend-fill" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stop-color="var(--color-brand)" stop-opacity="0.25" />
                        <stop offset="100%" stop-color="var(--color-brand)" stop-opacity="0" />
                    </linearGradient>
                </defs>

                @if (count($points) > 1)
                    {{-- The area under the line, closed along the baseline. --}}
                    <polygon points="{{ $points[0]['x'] }},100 {{ $polyline }} {{ end($points)['x'] }},100"
                        fill="url(#trend-fill)" />
                    {{-- vector-effect keeps the stroke 2px wide despite the
                         non-uniform scaling preserveAspectRatio=none applies. --}}
                    <polyline points="{{ $polyline }}" fill="none" stroke="var(--color-brand)" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke" />
                @endif
            </svg>

            {{-- The dots are HTML, not <circle>, and that is the point.
                 preserveAspectRatio="none" is what lets the line fill any card
                 width, but it scales x and y by different factors — and while
                 vector-effect protects a STROKE from that, nothing protects a
                 filled shape. The circles were being drawn as ellipses, flatter
                 the shorter the chart got, which is exactly the wrong direction
                 now that the chart is short. Positioned in percentages over the
                 same box, they stay round at every size.

                 The viewBox y axis and CSS `top` both grow downward, so the
                 coordinate carries over unchanged. --}}
            @foreach ($points as $point)
                <span class="absolute h-2.5 w-2.5 -translate-x-1/2 -translate-y-1/2 rounded-full bg-brand"
                    style="left: {{ $point['x'] }}%; top: {{ $point['y'] }}%"
                    title="{{ __('dashboard.response_trend.cell', [
                        'day' => $point['day']['label'],
                        'value' => $format($point['day']['ms']),
                    ]) }}"></span>
            @endforeach

            <span class="sr-only">{{ __('dashboard.response_trend.description') }}</span>
        </div>

        <div class="mt-3 flex justify-between text-[10px] text-muted dark:text-night-muted">
            <span>{{ $series[0]['label'] }}</span>
            <span class="font-medium">{{ $format($peak) }}</span>
            <span>{{ end($series)['label'] }}</span>
        </div>
    @endif
</div>
