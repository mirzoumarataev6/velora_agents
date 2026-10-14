@php
    /** @var \App\Services\Bi\Dashboard\RenderedWidget $item */
    $widget = $item->widget;
    $dimensionColumn = $widget->spec['dimensions'][0]['column'] ?? null;
    $timeAxis = in_array($widget->type, [\App\Services\Bi\Dashboard\WidgetType::Area, \App\Services\Bi\Dashboard\WidgetType::Line, \App\Services\Bi\Dashboard\WidgetType::Column], true)
        && $widget->dataset?->columns?->firstWhere('physical_name', $dimensionColumn)?->type?->isTemporal();
@endphp

{{-- ONE CARD.

     Rendered server-side, complete, on first paint — see DashboardController's
     docblock for why. The card therefore has to express three states in static
     HTML: it worked, it worked and there is nothing to show, and it failed.
     All three are below, and none of them is a spinner. --}}
<div class="card widget-card">
    {{-- The header doubles as the drag handle (`.widget-head`), which is why
         the body keeps normal text selection: making the whole card draggable
         is the usual way to end up unable to copy a number out of a table. --}}
    <div class="widget-head flex items-start justify-between gap-2 px-4 pt-3 pb-2">
        <div class="min-w-0">
            <h3 class="line-clamp-2 text-sm font-medium leading-snug text-ink dark:text-night-ink" title="{{ $widget->title }}">{{ $widget->title }}</h3>

            <div class="mt-1 flex flex-wrap items-center gap-x-1.5 gap-y-0.5 text-xs text-muted dark:text-night-muted">
                <span class="min-w-0 max-w-full truncate" title="{{ $widget->dataset?->name }}">{{ $widget->dataset?->name }}</span>

                @if (! $item->failed())
                    {{-- The cost, shown rather than hidden. An owner deciding
                         why a board feels slow should be able to see which card
                         is the slow one, and a cached card should say so rather
                         than letting stale-looking numbers go unexplained. --}}
                    <span aria-hidden="true">·</span>
                    <span class="shrink-0 whitespace-nowrap tabular-nums">
                        @if ($item->cached())
                            {{ __('bi.widgets.cached') }}
                        @else
                            {{ __('bi.widgets.took', ['ms' => $item->durationMs()]) }}
                        @endif
                    </span>
                @endif

                @if ($item->overriddenFilters !== [])
                    {{-- The card is deliberately ignoring a board filter. Said
                         out loud, because the alternative is one card quietly
                         disagreeing with its neighbours — see FilterMerger. --}}
                    <span aria-hidden="true">·</span>
                    <span class="text-warning" title="{{ __('bi.widgets.overridden') }}">
                        {{ __('bi.dashboards.filters') }}
                    </span>
                @endif
            </div>
        </div>

        @can('update', $widget)
        <div class="relative shrink-0" x-data="{open:false}" @click.outside="open=false">
            @if ($timeAxis)
                <select class="field mr-1 !w-24 !py-1 text-xs" aria-label="{{ __('bi.widgets.dimension') }}"
                    @mousedown.stop @change="changeGrain({{ $widget->id }}, $event.target.value)">
                    @foreach (\App\Services\Bi\Query\TimeGrain::cases() as $grain)
                        <option value="{{ $grain->value }}" @selected(($widget->spec['dimensions'][0]['grain'] ?? 'month') === $grain->value)>{{ __('bi.grains.'.$grain->value) }}</option>
                    @endforeach
                </select>
            @endif
            <button type="button" class="btn-ghost !px-2 !py-1 text-lg leading-none" @click="open=!open"
                :aria-expanded="open" aria-label="{{ __('bi.widgets.edit') }}">⋯</button>
            <div x-show="open" x-cloak class="absolute right-0 top-8 z-30 min-w-40 rounded-xl border border-hairline bg-surface p-1 shadow-xl dark:border-night-raised dark:bg-night-card">
                <button type="button" class="block w-full rounded-lg px-3 py-2 text-left text-xs hover:bg-sunk dark:hover:bg-night-raised" @click="editWidget({{ $widget->id }});open=false">{{ __('bi.widgets.edit') }}</button>
                <button type="button" class="block w-full rounded-lg px-3 py-2 text-left text-xs hover:bg-sunk dark:hover:bg-night-raised" @click="duplicateWidget({{ $widget->id }});open=false">{{ __('bi.widgets.copy') }}</button>
                <button type="button" class="block w-full rounded-lg px-3 py-2 text-left text-xs text-danger hover:bg-sunk dark:hover:bg-night-raised" @click="removeWidget({{ $widget->id }});open=false">{{ __('bi.data.delete') }}</button>
            </div>
        </div>
        @endcan
    </div>

    <div class="widget-body px-4 pb-3">
        @if ($item->failed())
            {{-- A FAILED CARD IS NOT A BROKEN BOARD. The sentence is the
                 classified code translated — the same vocabulary ingestion
                 uses — and the retry button appears only for the codes that can
                 actually succeed next time. Offering "try again" on "this
                 column no longer exists" would be a lie. --}}
            <div class="flex h-full flex-col items-start justify-center gap-2 text-sm">
                <p class="text-danger">{{ __($item->errorKey()) }}</p>

                @if ($item->transient)
                    <button type="button" class="btn-ghost text-xs" @click="refreshWidget({{ $widget->getKey() }})">
                        {{ __('bi.widgets.retry') }}
                    </button>
                @endif
            </div>
        @elseif ($item->isEmpty())
            {{-- Empty is not an error, and saying "no rows match these filters"
                 is a materially different and more useful sentence than an
                 error would be. --}}
            <div class="flex h-full items-center justify-center">
                <p class="text-sm text-muted dark:text-night-muted">{{ __('bi.widgets.no_data') }}</p>
            </div>
        @elseif (in_array($widget->type, [\App\Services\Bi\Dashboard\WidgetType::Line, \App\Services\Bi\Dashboard\WidgetType::Area, \App\Services\Bi\Dashboard\WidgetType::Bar, \App\Services\Bi\Dashboard\WidgetType::Column, \App\Services\Bi\Dashboard\WidgetType::Donut], true))
            @php $chartPayload = [
                'title' => $widget->title,
                'type' => $widget->type->value,
                'rows' => $item->result->rows,
                'columns' => $item->result->columns,
                'otherLabel' => __('bi.widgets.other', ['count' => max(1, count($item->result->rows) - 5)]),
                'totalLabel' => __('bi.widgets.total'),
                'emptyLabel' => __('bi.widgets.no_data'),
                'stacked' => (bool) $widget->option('stacked', false),
            ]; @endphp
            @php
                $insight = null;
                if ($widget->type === \App\Services\Bi\Dashboard\WidgetType::Donut && count($item->result->rows) > 1) {
                    $insightRows = $item->result->rows;
                    $sum = array_sum(array_map(fn ($row) => (float) ($row['m0'] ?? 0), $insightRows));
                    if ($sum > 0) {
                        usort($insightRows, fn ($a, $b) => ((float) ($b['m0'] ?? 0)) <=> ((float) ($a['m0'] ?? 0)));
                        $leader = $insightRows[0];
                        $insight = __('bi.analysis.share_insight', [
                            'label' => $leader['d0'] ?? '—',
                            'percent' => number_format(100 * (float) $leader['m0'] / $sum, 1, app()->getLocale() === 'en' ? '.' : ','),
                        ]);
                    }
                }
            @endphp
            <div class="{{ $insight ? 'h-[210px]' : 'h-full min-h-44' }} w-full" data-bi-chart="{{ json_encode($chartPayload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) }}"
                role="img" aria-label="{{ $widget->title }}">
                <div class="h-full w-full animate-pulse rounded-xl bg-sunk/60 dark:bg-night-raised/40" aria-hidden="true"></div>
            </div>
            @if ($insight)<p class="truncate pt-1 text-xs text-muted dark:text-night-muted" title="{{ $insight }}">{{ $insight }}</p>@endif
        @else
            <x-dynamic-component :component="$widget->type->component()"
                :widget="$widget" :result="$item->result" :trend="$item->trend" />
        @endif
    </div>
</div>
