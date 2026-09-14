@extends('layouts.dashboard')

@section('title', __('dashboard.title'))

@php
    $summary = $metrics['summary'];
    $range = $metrics['range'];
@endphp

@section('content')
<div class="mx-auto max-w-6xl">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold">{{ __('dashboard.heading') }}</h1>
            <p class="mt-1 text-sm text-muted dark:text-night-muted">{{ __('dashboard.subheading') }}</p>
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
                <select id="dashboard_agent_filter" x-model="agentId"
                    class="field rounded-xl px-3 py-2 text-sm font-medium">
                    <option value="">{{ __('dashboard.agent_filter.all') }}</option>
                    @foreach ($agents as $agentOption)
                        <option value="{{ $agentOption->id }}">{{ $agentOption->name }}</option>
                    @endforeach
                </select>
            </div>
        @endif
    </div>

    @if (! $metrics['has_history'])
        {{-- One honest empty state for the whole page rather than eight lonely
             zeroes. An owner who has not connected a channel yet learns nothing
             from a grid of noughts, and a dashboard that shows sample data once
             is a dashboard nobody trusts afterwards. --}}
        <div class="reveal mt-8 flex flex-col items-center justify-center rounded-3xl border border-dashed border-black/10 bg-white/50 px-6 py-20 text-center dark:border-white/10 dark:bg-white/5">
            <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-brand/10 text-brand">
                <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3v18h18M7 15l4-4 3 3 5-6"/></svg>
            </div>
            <h2 class="mt-4 text-lg font-semibold">{{ __('dashboard.empty.title') }}</h2>
            <p class="mt-1.5 max-w-md text-sm text-muted dark:text-night-muted">{{ __('dashboard.empty.description') }}</p>
            <a href="{{ route('agents.index') }}"
                class="mt-5 flex items-center gap-2 rounded-full bg-brand px-5 py-2.5 text-sm font-semibold text-on-accent transition-all hover:-translate-y-0.5 hover:bg-brand-hover">
                {{ __('dashboard.empty.cta') }}
            </a>
        </div>
    @else
        {{-- Row 1 · the five headline numbers --}}
        <div class="reveal mt-6 grid grid-cols-2 gap-3 lg:grid-cols-5">
            @include('dashboard.partials.stat', [
                'label' => __('dashboard.summary.active_conversations'),
                'value' => $summary['active_conversations'],
                'hint' => __('dashboard.summary.active_conversations_hint'),
                // The only figure here that describes this moment rather than
                // the period, so the only one that gets the live dot.
                'live' => true,
            ])
            @include('dashboard.partials.stat', [
                'label' => __('dashboard.summary.leads_today'),
                'value' => $summary['leads_today'],
                'hint' => null,
            ])
            @include('dashboard.partials.stat', [
                'label' => __('dashboard.summary.leads_week'),
                'value' => $summary['leads_week'],
                'hint' => null,
            ])
            @include('dashboard.partials.stat', [
                'label' => __('dashboard.summary.conversion'),
                'value' => $summary['conversion_rate'] === null ? '—' : $summary['conversion_rate'].'%',
                'hint' => __('dashboard.summary.conversion_hint'),
            ])
            @include('dashboard.partials.stat', [
                'label' => __('dashboard.summary.response_time'),
                'value' => $summary['avg_response_ms'] === null
                    ? '—'
                    : ($summary['avg_response_ms'] >= 1000
                        ? round($summary['avg_response_ms'] / 1000, 1).' с'
                        : round($summary['avg_response_ms']).' мс'),
                'hint' => __('dashboard.summary.response_time_hint'),
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

        {{-- Row 5 · the lead pipeline, twice over: how it fills, and where it
             is currently stuck. --}}
        <div class="reveal mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
            @include('dashboard.partials.funnel')
            @include('dashboard.partials.leads-status')
        </div>

        {{-- Row 6 · the two time-shaped views: how fast the answers come, and
             when the questions arrive. --}}
        <div class="reveal mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
            @include('dashboard.partials.response-trend')
            @include('dashboard.partials.heatmap')
        </div>

        {{-- Row 7 · only for an owner running more than one assistant; the
             metrics return an empty array below two and the card disappears
             rather than comparing one thing with itself. --}}
        @if ($metrics['agent_comparison'] !== [])
            <div class="reveal mt-4">
                @include('dashboard.partials.agents-compare')
            </div>
        @endif

        {{-- Row 7 · the two demand lists, side by side: what was asked for, and
             what could not be answered. They read together. --}}
        <div class="reveal mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
            @include('dashboard.partials.products')
            @include('dashboard.partials.unanswered')
        </div>
    @endif
</div>
@endsection
