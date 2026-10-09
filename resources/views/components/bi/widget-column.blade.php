@props(['widget', 'result'])

@include('partials.widget-helpers')

@php
    /* Vertical bars — magnitude by category, read left to right.

       BUILT IN HTML, NOT SVG, and that is a correction worth recording. The
       first version drew the bars and their axis labels inside one SVG with a
       640×260 viewBox. The bars were fine; the <text> was not. An SVG scaled to
       fill a card scales EVERYTHING in it uniformly, so on a 1000px-wide card
       the 11px axis labels rendered at ~18px and the chart looked like a
       child's poster. That is the same hazard components/area-chart.blade.php
       documents — it solves it by keeping the labels in HTML outside the SVG —
       and once the labels are HTML, the bars have no reason to be SVG either:
       a bar's height is a percentage of its track, which CSS does natively and
       more accurately than viewBox arithmetic.

       What SVG would still buy is a single path for many marks. At the twenty
       categories this type caps at, that is not a cost worth a scaling bug. */
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
    $gridLines = [1.0, 0.5, 0.0];

    $legend = [];
    foreach ($measures as $i => $measure) {
        $legend[] = [
            'alias' => $measure['alias'],
            'class' => velora_series_class($i),
            'label' => ($measure['label'] ?: __('bi.aggregates.'.$measure['aggregate'])),
        ];
    }

    // Past this many categories the labels collide, so only every nth is drawn
    // — the bars all stay, because dropping a bar would drop data.
    $labelEvery = max(1, (int) ceil(count($rows) / 9));
@endphp

<div class="flex h-full min-h-0 flex-col">
    <div class="flex min-h-0 flex-1 gap-2">
        {{-- The y-axis. `justify-between` on a box exactly the height of the
             plot puts each label on its gridline with no arithmetic. --}}
        <div class="flex w-14 shrink-0 flex-col justify-between pb-5 text-right" aria-hidden="true">
            @foreach ($gridLines as $fraction)
                <span class="chart-axis-label whitespace-nowrap leading-none">{{ velora_bi_number($ceiling * $fraction, true) }}</span>
            @endforeach
        </div>

        <div class="relative min-w-0 flex-1">
            {{-- Gridlines, behind the bars. Inset at the bottom by the label
                 row's height so the baseline sits where the bars actually
                 start. --}}
            <div class="pointer-events-none absolute inset-x-0 top-0 bottom-5 flex flex-col justify-between" aria-hidden="true">
                @foreach ($gridLines as $fraction)
                    <div class="h-px w-full bg-hairline dark:bg-night-raised"></div>
                @endforeach
            </div>

            <div class="relative flex h-full items-end gap-1">
                @foreach ($rows as $g => $row)
                    <div class="flex h-full min-w-0 flex-1 flex-col">
                        {{-- The bars of one category. `items-end` so they grow
                             up from the baseline; `gap-0.5` is the 2px
                             separation between series within a group. --}}
                        <div class="flex min-h-0 flex-1 items-end justify-center gap-0.5">
                            @foreach ($measures as $s => $measure)
                                @php
                                    $value = (float) ($row[$measure['alias']] ?? 0);
                                    $percent = $ceiling > 0 ? max(0.0, $value / $ceiling) * 100 : 0.0;
                                @endphp
                                {{-- A zero still draws 2px, so the category
                                     reads as present-and-zero rather than
                                     missing. Silently omitting a zero is the
                                     most misleading thing a bar chart can do.

                                     The radius is on the TOP corners only: a
                                     rounded bottom would lift the bar off the
                                     axis and make a small value look like it
                                     starts above zero. --}}
                                <div class="{{ velora_series_class($s) }} w-full max-w-10 rounded-t bg-current transition-[height] duration-500"
                                    style="height: {{ round(max($percent, 0), 2) }}%; min-height: 2px"
                                    title="{{ velora_bi_axis_label($row[$dimension['alias'] ?? ''] ?? null, $dimension['grain'] ?? null, 40) }} — {{ $measure['label'] ?: __('bi.aggregates.'.$measure['aggregate']) }}: {{ velora_bi_number($value) }}"></div>
                            @endforeach
                        </div>

                        <div class="h-5 shrink-0 pt-1 text-center">
                            @if ($g % $labelEvery === 0)
                                <span class="chart-axis-label block truncate">
                                    {{ velora_bi_axis_label($row[$dimension['alias'] ?? ''] ?? null, $dimension['grain'] ?? null, 12) }}
                                </span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    <x-bi.widget-legend :entries="$legend" />
</div>
