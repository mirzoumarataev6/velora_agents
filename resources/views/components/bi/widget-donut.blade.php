@props(['widget', 'result'])

@include('partials.widget-helpers')

@php
    use App\Services\Bi\Dashboard\WidgetType;

    /* Composition — the one question a donut answers better than a bar chart,
       and only when there are few enough slices to compare by angle.

       THE "OTHER" SLICE is the important part of this file. The query asks for
       SERIES_SLOTS + 1 categories precisely so this view can tell "exactly six"
       from "more than six". Past six it keeps the top five and sums the rest
       into one neutral slice, rather than cycling the palette — two different
       regions drawn in the same blue is not a crowded chart, it is a wrong one.
       The count is named in the label so nothing is hidden. */
    $dimensions = array_values(array_filter($result->columns, fn ($c) => $c['kind'] === 'dimension'));
    $measures = array_values(array_filter($result->columns, fn ($c) => $c['kind'] === 'measure'));

    $dimension = $dimensions[0] ?? null;
    $measure = $measures[0] ?? null;

    $slices = [];
    foreach ($result->rows as $row) {
        $slices[] = [
            'label' => velora_bi_axis_label($row[$dimension['alias'] ?? ''] ?? null, $dimension['grain'] ?? null, 24),
            // Negative values have no meaning in a part-of-whole chart — a
            // slice cannot be -8% of a circle. Clamped rather than dropped, so
            // the category still appears in the legend with a zero.
            'value' => max(0.0, (float) ($row[$measure['alias'] ?? ''] ?? 0)),
        ];
    }

    $slots = WidgetType::SERIES_SLOTS;
    $overflowed = count($slices) > $slots;

    if ($overflowed) {
        $kept = array_slice($slices, 0, $slots - 1);
        $rest = array_slice($slices, $slots - 1);

        $kept[] = [
            'label' => __('bi.widgets.other', ['count' => count($rest)]),
            'value' => array_sum(array_column($rest, 'value')),
            'other' => true,
        ];

        $slices = $kept;
    }

    $total = array_sum(array_column($slices, 'value'));

    $size = 220.0;
    $centre = $size / 2;
    $stroke = 30.0;
    $radius = $centre - $stroke / 2 - 2;

    $legend = [];
    $cursor = 0.0;
    $arcs = [];

    foreach ($slices as $i => $slice) {
        $fraction = $total > 0 ? $slice['value'] / $total : 0.0;
        $class = ($slice['other'] ?? false) ? 'series-other' : velora_series_class($i);

        $arcs[] = [
            'd' => velora_bi_arc($centre, $centre, $radius, $cursor, $cursor + $fraction),
            'class' => $class,
            'label' => $slice['label'],
            'value' => $slice['value'],
            'percent' => $fraction * 100,
            'draw' => $fraction > 0,
        ];

        $legend[] = [
            'alias' => 's'.$i,
            'class' => $class,
            'label' => $slice['label'].' · '.round($fraction * 100).'%',
        ];

        $cursor += $fraction;
    }
@endphp

<div class="flex h-full flex-col items-center justify-center">
    <div class="relative">
        <svg width="{{ $size }}" height="{{ $size }}" viewBox="0 0 {{ $size }} {{ $size }}"
            role="img" aria-label="{{ $widget->title }}">
            @foreach ($arcs as $arc)
                @if ($arc['draw'])
                    {{-- `bar-gap` paints a 2px surface-coloured stroke along the
                         slice boundary, so neighbouring arcs are separated
                         without either of them being shortened — shrinking the
                         arcs would misstate the proportions, which is the one
                         thing a pie must not do. --}}
                    <path class="donut-arc {{ $arc['class'] }}" d="{{ $arc['d'] }}"
                        stroke-width="{{ $stroke }}" />
                    <path class="donut-arc bar-gap" d="{{ $arc['d'] }}" stroke-width="{{ $stroke }}"
                        fill="none" stroke-opacity="0" />
                    <path class="donut-arc {{ $arc['class'] }}" d="{{ $arc['d'] }}"
                        stroke-width="{{ $stroke }}" stroke-opacity="0">
                        <title>{{ $arc['label'] }}: {{ velora_bi_number($arc['value']) }} ({{ round($arc['percent'], 1) }}%)</title>
                    </path>
                @endif
            @endforeach
        </svg>

        {{-- The hole earns its keep by holding the total. An empty donut hole
             is just a pie chart with a bite out of it. --}}
        <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
            <span class="text-xl font-semibold tabular-nums text-ink dark:text-night-ink">
                {{ velora_bi_number($total, true) }}
            </span>
            <span class="text-xs text-muted dark:text-night-muted">{{ __('bi.widgets.total') }}</span>
        </div>
    </div>

    <x-bi.widget-legend :entries="$legend" />
</div>
