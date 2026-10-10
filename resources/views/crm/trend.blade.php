@extends('layouts.dashboard')

@section('title', __('crm.trend.title'))

@section('content')
<div class="crm-page">
    <div class="page-header">
        <div>
            <h1 class="page-title">{{ __('crm.trend.heading') }}</h1>
            <p class="page-sub">{{ __('crm.trend.subheading') }}</p>
        </div>
    </div>

    @include('crm.partials.subnav')

    {{-- Plain GET links, like the customer directory's own filters — a full
         page reload is a fair price for a page this light, and it keeps the
         range/workspace choice shareable as a URL without any JS state. --}}
    <section class="mt-5 flex flex-wrap items-center gap-3">
        <div class="tabs">
            @foreach (\App\Services\Crm\DealTrend::WINDOWS as $range)
                <a href="{{ route('crm.trend', array_filter(['range' => $range, 'workspace' => $workspaceId])) }}"
                    class="tab {{ $window === $range ? 'tab-active' : '' }}">
                    {{ __('crm.trend.range_'.$range) }}
                </a>
            @endforeach
        </div>

        <select class="field w-auto text-sm" aria-label="{{ __('crm.workspaces.heading') }}"
            onchange="window.location = this.value">
            <option value="{{ route('crm.trend', ['range' => $window]) }}" @selected(! $workspaceId)>{{ __('crm.workspaces.all') }}</option>
            @foreach ($workspaces as $workspace)
                <option value="{{ route('crm.trend', ['range' => $window, 'workspace' => $workspace->id]) }}" @selected($workspaceId === $workspace->id)>
                    {{ $workspace->displayName() }}
                </option>
            @endforeach
        </select>
    </section>

    <section class="mt-4 grid grid-cols-3 gap-3">
        <div class="card card-pad-sm">
            <p class="text-xs text-muted dark:text-night-muted">{{ __('crm.trend.new_deals') }}</p>
            <p class="tnum mt-1 text-2xl font-semibold">{{ $series['totals']['new_deals'] }}</p>
        </div>
        <div class="card card-pad-sm">
            <p class="text-xs text-muted dark:text-night-muted">{{ __('crm.trend.won') }}</p>
            <p class="tnum mt-1 text-2xl font-semibold">{{ $series['totals']['won'] }}</p>
        </div>
        <div class="card card-pad-sm">
            <p class="text-xs text-muted dark:text-night-muted">{{ __('crm.trend.revenue') }}</p>
            <p class="tnum mt-1 text-2xl font-semibold">{{ \App\Models\Lead::formatPrice($series['totals']['revenue']) ?: '—' }}</p>
        </div>
    </section>

    @php
        $grain = $window === '6m' ? 'month' : 'day';
        $rows = collect($series['buckets'])->map(fn ($bucket) => [
            'd0' => $bucket['key'],
            'm0' => $bucket['new_deals'],
            'm1' => $bucket['won'],
        ])->all();
        $countsPayload = [
            'title' => __('crm.trend.heading'),
            'type' => 'line',
            'columns' => [
                ['kind' => 'dimension', 'alias' => 'd0', 'label' => '', 'grain' => $grain],
                ['kind' => 'measure', 'alias' => 'm0', 'label' => __('crm.trend.new_deals')],
                ['kind' => 'measure', 'alias' => 'm1', 'label' => __('crm.trend.won')],
            ],
            'rows' => $rows,
            'emptyLabel' => __('crm.trend.empty'),
            'stacked' => false,
        ];
        $revenuePayload = [
            'title' => __('crm.trend.revenue'),
            'type' => 'area',
            'columns' => [
                ['kind' => 'dimension', 'alias' => 'd0', 'label' => '', 'grain' => $grain],
                ['kind' => 'measure', 'alias' => 'm0', 'label' => __('crm.trend.revenue')],
            ],
            'rows' => collect($series['buckets'])->map(fn ($bucket) => ['d0' => $bucket['key'], 'm0' => $bucket['revenue']])->all(),
            'emptyLabel' => __('crm.trend.empty'),
            'stacked' => false,
        ];
    @endphp

    {{-- Two charts, not one: counts and revenue sit on wildly different
         scales (a handful of deals against thousands of somoni), and one
         shared y-axis would flatten whichever series is smaller into an
         unreadable line. Both reuse the BI module's own ApexCharts renderer
         (bi-charts.js) rather than a new charting dependency — the payload
         shape (columns/rows with dimension/measure aliases) is the same
         generic contract BI widgets already use. --}}
    <section class="mt-4 grid gap-4 lg:grid-cols-2">
        <div class="card card-pad">
            <p class="mb-2 text-sm font-semibold">{{ __('crm.trend.new_deals') }} / {{ __('crm.trend.won') }}</p>
            <div class="h-64 w-full" data-bi-chart="{{ json_encode($countsPayload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) }}"
                role="img" aria-label="{{ __('crm.trend.new_deals') }}">
                <div class="h-full w-full animate-pulse rounded-xl bg-sunk/60 dark:bg-night-raised/40" aria-hidden="true"></div>
            </div>
        </div>
        <div class="card card-pad">
            <p class="mb-2 text-sm font-semibold">{{ __('crm.trend.revenue') }}</p>
            <div class="h-64 w-full" data-bi-chart="{{ json_encode($revenuePayload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) }}"
                role="img" aria-label="{{ __('crm.trend.revenue') }}">
                <div class="h-full w-full animate-pulse rounded-xl bg-sunk/60 dark:bg-night-raised/40" aria-hidden="true"></div>
            </div>
        </div>
    </section>
</div>

@push('scripts')
{{-- Runs once per visit — full load or Turbo navigation both re-execute a
     pushed <script> in the new page body — so no listener needs attaching
     or cleaning up. mountBiCharts() is idempotent per element, and the
     module-level `turbo:before-cache` hook in app.js already tears every
     chart instance down when this page is navigated away from. --}}
<script>
    window.loadBiCharts().then((mount) => mount());
</script>
@endpush
@endsection
