@props(['widget', 'result'])

@include('partials.widget-helpers')

@php
    /* Horizontal bars — the right form when the CATEGORY NAMES are long.
       "ООО «Ромашка»" fits on a row and does not fit under a column, and the
       alternative in a vertical chart is rotated labels, which are slower to
       read than any layout change.

       Built in HTML rather than SVG, deliberately. A horizontal bar's length
       is a percentage of the row width and its label is ordinary text that
       must wrap and truncate like text — both of which the browser does better
       than any viewBox arithmetic, and neither of which needs a path. The
       vertical sibling is SVG because its geometry genuinely is 2D. */
    $dimensions = array_values(array_filter($result->columns, fn ($c) => $c['kind'] === 'dimension'));
    $measures = array_values(array_filter($result->columns, fn ($c) => $c['kind'] === 'measure'));

    $dimension = $dimensions[0] ?? null;
    $rows = $result->rows;

    $values = [];
    foreach ($rows as $row) {
        foreach ($measures as $measure) {
            $values[] = (float) ($row[$measure['alias']] ?? 0);
        }
    }

    $ceiling = velora_bi_ceiling($values);

    $legend = [];
    foreach ($measures as $i => $measure) {
        $legend[] = [
            'alias' => $measure['alias'],
            'class' => velora_series_class($i),
            'label' => ($measure['label'] ?: __('bi.aggregates.'.$measure['aggregate'])),
        ];
    }
@endphp

<div class="flex h-full flex-col">
    <div class="widget-body space-y-2.5 pr-1">
        @foreach ($rows as $row)
            <div>
                <div class="flex items-baseline justify-between gap-3">
                    <span class="truncate text-xs text-ink-soft dark:text-night-ink"
                        title="{{ $row[$dimension['alias'] ?? ''] ?? '' }}">
                        {{ velora_bi_axis_label($row[$dimension['alias'] ?? ''] ?? null, $dimension['grain'] ?? null, 32) }}
                    </span>
                    {{-- The value is DIRECT-LABELLED on every row. That is
                         affordable here in a way it is not on a column chart
                         (where it would collide at eight categories), and it is
                         what lets this form double as its own data table. --}}
                    <span class="shrink-0 text-xs tabular-nums text-muted dark:text-night-muted">
                        {{ velora_bi_number($row[$measures[0]['alias'] ?? ''] ?? null, true) }}
                    </span>
                </div>

                <div class="mt-1 space-y-1">
                    @foreach ($measures as $s => $measure)
                        @php
                            $value = (float) ($row[$measure['alias']] ?? 0);
                            $percent = $ceiling > 0 ? max(0.0, $value / $ceiling) * 100 : 0.0;
                        @endphp
                        {{-- The track is the sunk surface, so a short bar still
                             shows the scale it is short against — a bare bar on
                             a white card gives the reader nothing to judge it
                             by. --}}
                        <div class="h-2 w-full overflow-hidden rounded-full bg-sunk dark:bg-night-raised">
                            <div class="{{ velora_series_class($s) }} h-full rounded-full bg-current transition-[width] duration-500"
                                style="width: {{ round(max($percent, $value > 0 ? 1.5 : 0), 2) }}%"
                                title="{{ $measure['label'] ?: __('bi.aggregates.'.$measure['aggregate']) }}: {{ velora_bi_number($value) }}"></div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>

    <x-bi.widget-legend :entries="$legend" />
</div>
