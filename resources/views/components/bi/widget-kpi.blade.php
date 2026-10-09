@props(['widget', 'result', 'trend' => null])

@include('partials.widget-helpers')

@php
    /* A single number, which is the right form when the answer IS one number.
       Drawing one value as a one-bar chart wastes the card and makes the reader
       decode a length to recover a quantity they could simply have read. */
    $measure = $result->columns[0] ?? null;
    $value = $measure ? ($result->rows[0][$measure['alias']] ?? null) : null;

    $unit = $widget->option('unit');
    $abbreviate = (bool) $widget->option('abbreviate', true);
@endphp

<div class="flex h-full flex-col justify-center">
    <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
        {{-- The hero number wears INK, not a series colour: there is only one
             value, so colour is carrying no information, and the brand blue
             here would make every KPI card look like a link. --}}
        <span class="min-w-0 text-[clamp(1.75rem,2.5vw,2.25rem)] font-semibold leading-tight tabular-nums text-ink dark:text-night-ink"
            title="{{ velora_bi_number($value) }}{{ $unit ? ' '.$unit : '' }}">
            {{ velora_bi_number($value, $abbreviate) }}
        </span>

        @if ($unit)
            <span class="whitespace-nowrap text-sm text-muted dark:text-night-muted">{{ $unit }}</span>
        @endif
    </div>

    @if ($measure)
        <span class="mt-1 break-words text-xs leading-relaxed text-muted dark:text-night-muted">
            {{ __('bi.aggregates.'.$measure['aggregate']) }}@if ($measure['label']) · {{ $measure['label'] }}@endif
        </span>
    @endif
    @if ($trend !== null)
        <span class="mt-2 inline-flex w-fit items-center rounded-full px-2 py-0.5 text-xs font-semibold {{ $trend >= 0 ? 'badge-success' : 'badge-danger' }}">
            {{ $trend >= 0 ? '↗' : '↘' }} {{ $trend >= 0 ? '+' : '−' }}{{ number_format(abs($trend), 1, app()->getLocale() === 'en' ? '.' : ',', app()->getLocale() === 'en' ? ',' : ' ') }}% {{ __('bi.analysis.vs_previous_month') }}
        </span>
    @endif
</div>
