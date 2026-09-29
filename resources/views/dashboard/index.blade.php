@extends('layouts.dashboard')

@section('title', __('dashboard.title'))

@php
    $summary = $metrics['summary'];
    $range = $metrics['range'];
@endphp

@section('content')
@include('partials.chart-helpers')

@php
    /* The daily series behind the sparklines and trend pills. `daily` is two
       grouped reads added to DashboardMetrics for exactly this; everything
       else here is reshaped from what the payload already carried.

       One metric still has no line, and cannot: "активные диалоги" describes
       this moment, not a period, so there is no previous value to compare it
       against. It gets a real second fact instead of an invented curve. */
    $daily = $metrics['daily'];
    $messageSeries = array_column($metrics['series'], 'total');
    $messagesTotal = array_sum($messageSeries);

    // Response time: the daily averages, with the days that saw no replies
    // dropped rather than plotted as zero — a day with no traffic is not a day
    // the assistant answered instantly.
    $responseSeries = array_values(array_filter(
        array_column($metrics['response_trend']['series'], 'ms'),
        fn ($ms) => $ms !== null
    ));

    /* Conversion: the days with no conversations at all are dropped rather
       than plotted as 0% — a quiet day is not a day the assistant converted
       nobody, and a zero would drag the line to the floor and misreport the
       trend. */
    $conversionSeries = array_values(array_filter($daily['conversion'], fn ($rate) => $rate !== null));

    $funnel = collect($metrics['funnel'])->keyBy('key');

    /* The eyebrow. A real date rather than a decorative line: it tells the
       owner which day's numbers they are looking at, which the old blue band
       never did. Localised through Carbon so tg/ru/en each read naturally. */
    $today = \Carbon\CarbonImmutable::now(config('agent.analytics.timezone') ?: config('app.timezone'))
        ->locale(app()->getLocale());
@endphp

<div>
    {{-- THE PAGE HEADER — what replaced the flat blue band.

         The band was a #2f66c4 slab with a 44px white "Аналитика" across it:
         the most dated element on the page, and the most expensive, spending
         the top 120px on a word the sidebar had already highlighted. This
         costs a third of the height, says something true (today's date, the
         window being shown), and puts the controls where the eye already is.
         --}}
    <div class="page-header">
        <div>
            <p class="page-eyebrow">{{ $today->isoFormat('dddd, D MMMM') }}</p>
            {{-- A greeting rather than the section name: "Аналитика" is already the
                 highlighted nav item, so repeating it in 26px is the one thing
                 the title definitely should not say. The first word of the
                 account name, not the whole string — "Добрый день, Олами
                 Компютерхо ООО" is not a greeting. --}}
            <h1 class="page-title">{{ __('dashboard.greeting', ['name' => \Illuminate\Support\Str::of(auth()->user()->name)->trim()->explode(' ')->first()]) }}</h1>
            <p class="page-sub">{{ __('dashboard.subheading') }}</p>
        </div>

        <div class="page-actions">
            {{-- The range switch, promoted out of the chart card's corner to
                 the page header: it governs every card on the page, not just
                 the one it used to sit in. Plain links, not a JS toggle — the
                 range belongs in the URL so it survives a reload and can be
                 linked to, and the page is one query either way. --}}
            <div class="tabs" role="group" aria-label="{{ __('dashboard.range.label') }}">
                @foreach (\App\Services\Analytics\DashboardMetrics::RANGES as $option)
                    {{-- array_filter drops agent_id when it's null rather than
                         writing an empty "?agent_id=" — the selected agent must
                         survive switching ranges the same way the range survives
                         switching agents. --}}
                    <a href="{{ route('dashboard', array_filter(['range' => $option, 'agent_id' => $metrics['agent_id'] ?? null])) }}"
                        class="tab {{ $range === $option ? 'tab-active' : '' }}"
                        @if ($range === $option) aria-current="true" @endif>
                        {{ __('dashboard.messages_chart.range_'.$option) }}
                    </a>
                @endforeach
            </div>

            {{-- Pointless with one agent — same rule the "Нагрузка по агентам"
                 card uses: a filter over one thing is not a filter. --}}
            @if ($agents->count() > 1)
                <div x-data="{ agentId: {{ Js::from((string) ($metrics['agent_id'] ?? '')) }} }"
                    x-init="$watch('agentId', (value) => {
                        const url = new URL(window.location.href);
                        if (value) { url.searchParams.set('agent_id', value); } else { url.searchParams.delete('agent_id'); }
                        window.location.href = url.toString();
                    })">
                    <label for="dashboard_agent_filter" class="sr-only">{{ __('dashboard.agent_filter.label') }}</label>
                    <select id="dashboard_agent_filter" x-model="agentId" class="chip chip-on-card appearance-none pr-8">
                        <option value="">{{ __('dashboard.agent_filter.all') }}</option>
                        @foreach ($agents as $agentOption)
                            <option value="{{ $agentOption->id }}">{{ $agentOption->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
        </div>
    </div>

    @if (! $metrics['has_history'])
        {{-- One honest empty state for the whole page rather than eight lonely
             zeroes. An owner who has not connected a channel yet learns nothing
             from a grid of noughts, and a dashboard that shows sample data once
             is a dashboard nobody trusts afterwards. --}}
        <div class="card empty-state reveal">
            <span class="empty-state-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3v18h18M7 15l4-4 3 3 5-6"/></svg>
            </span>
            <h2 class="empty-state-title">{{ __('dashboard.empty.title') }}</h2>
            <p class="empty-state-text">{{ __('dashboard.empty.description') }}</p>
            <a href="{{ route('agents.index') }}" class="btn btn-primary btn-lg mt-4">
                {{ __('dashboard.empty.cta') }}
            </a>
        </div>
    @else
        {{-- Row 1 · the five headline numbers.

             Five across at xl, where 1600px gives each tile ~300px — enough
             for an icon, a figure, a pill and a sparkline without crowding.
             Two across on a phone. --}}
        <div class="reveal grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5">
            {{-- Messages, and it leads the row on purpose: it is the only
                 headline metric with a full daily series behind it, so it is
                 the one that can show its own shape. --}}
            @include('dashboard.partials.stat', [
                'label' => __('dashboard.summary.messages'),
                'value' => $messagesTotal,
                'hint' => __('dashboard.summary.messages_hint'),
                'icon' => 'messages',
                'series' => $messageSeries,
                'trend' => velora_trend($messageSeries),
            ])
            @include('dashboard.partials.stat', [
                'label' => __('dashboard.summary.active_conversations'),
                'value' => $summary['active_conversations'],
                'hint' => __('dashboard.summary.active_conversations_hint'),
                'icon' => 'chat',
                // The only figure here that describes this moment rather than
                // the period, so the only one that gets the live dot — and the
                // reason it carries no trend: "now" has no previous period.
                'live' => true,
                'context' => trans_choice('dashboard.summary.of_total_conversations', $summary['conversations_total'], [
                    'count' => number_format($summary['conversations_total'], 0, '.', ' '),
                ]),
            ])
            @include('dashboard.partials.stat', [
                'label' => __('dashboard.summary.leads_week'),
                'value' => $summary['leads_week'],
                'icon' => 'leads',
                'hint' => __('dashboard.summary.leads_week_hint'),
                'series' => $daily['leads'],
                'trend' => velora_trend($daily['leads']),
            ])
            @include('dashboard.partials.stat', [
                'label' => __('dashboard.summary.conversion'),
                'value' => $summary['conversion_rate'] === null ? '—' : $summary['conversion_rate'].'%',
                'hint' => __('dashboard.summary.conversion_hint'),
                'icon' => 'conversion',
                'series' => $conversionSeries,
                'trend' => velora_trend($conversionSeries),
            ])
            @include('dashboard.partials.stat', [
                'label' => __('dashboard.summary.response_time'),
                'value' => $summary['avg_response_ms'] === null
                    ? '—'
                    : ($summary['avg_response_ms'] >= 1000
                        ? round($summary['avg_response_ms'] / 1000, 1).' '.__('dashboard.units.seconds')
                        : round($summary['avg_response_ms']).' '.__('dashboard.units.ms')),
                'hint' => __('dashboard.summary.response_time_hint'),
                'icon' => 'clock',
                'series' => $responseSeries,
                'trend' => velora_trend($responseSeries),
                // Faster is better here, so a falling line is the green one.
                'trendTone' => 'lower-better',
            ])
        </div>

        {{-- Row 2 · the one decision to make right now, given the weight a
             decision deserves: full width, accent gradient, and a link to the
             screen where it gets made. Every other card here reports; this one
             asks. --}}
        @include('dashboard.partials.highlight')

        {{-- Row 3 · volume over time (2/3) beside what needs the owner (1/3) --}}
        <div class="reveal mt-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
            <div class="lg:col-span-2">
                @include('dashboard.partials.messages-chart')
            </div>
            @include('dashboard.partials.pending')
        </div>

        {{-- Row 4 · answer quality, full width and on its own.
             It is the only thing on this page that can tell the owner an answer
             was WRONG rather than missing, and half a grid row was reading as a
             minor chart rather than as the point. --}}
        <div class="reveal mt-4">
            @include('dashboard.partials.ratings')
        </div>

        {{-- The first screen answers how business is doing and what needs
             action. Deeper diagnostics stay one click away without making the
             presentation or the daily owner workflow scroll past every chart. --}}
        <details class="analytics-more mt-5">
            <summary class="analytics-more-toggle">
                <span>
                    <strong>{{ __('dashboard.details.title') }}</strong>
                    <span class="analytics-more-description">{{ __('dashboard.details.description') }}</span>
                </span>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6"/></svg>
            </summary>

            <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
                @include('dashboard.partials.funnel')
                @include('dashboard.partials.leads-status')
            </div>

            <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
                @include('dashboard.partials.response-trend')
                @include('dashboard.partials.heatmap')
            </div>

            @if ($metrics['agent_comparison'] !== [])
                <div class="mt-4">
                    @include('dashboard.partials.agents-compare')
                </div>
            @endif

            <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
                @include('dashboard.partials.products')
                @include('dashboard.partials.unanswered')
            </div>
        </details>
    @endif
</div>
@endsection
