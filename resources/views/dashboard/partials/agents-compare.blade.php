{{--
    Which agent is carrying the work.

    Rendered ONLY for an owner with more than one agent — DashboardMetrics
    returns an empty array below two, and the caller drops the card entirely. A
    comparison of one thing is not a comparison, and a single full-width bar
    labelled with the only agent's name is the kind of chart that gets added to
    fill a grid.

    Expects: $metrics.
--}}
@php
    $agents = $metrics['agent_comparison'];
    $peak = max(1, max(array_column($agents, 'messages')));
@endphp

<div class="flex h-full flex-col rounded-2xl border border-black/10 bg-white p-5 dark:border-white/10 dark:bg-white/5">
    <h2 class="text-base font-semibold">{{ __('dashboard.agents_compare.title') }}</h2>
    <p class="mt-1 text-xs text-muted dark:text-night-muted">{{ __('dashboard.agents_compare.description') }}</p>

    <div class="mt-5 space-y-3.5">
        @foreach ($agents as $agent)
            <div>
                <div class="flex items-baseline justify-between gap-3">
                    <span class="min-w-0 truncate text-sm font-medium">{{ $agent['name'] }}</span>
                    <span class="shrink-0 text-xs text-muted dark:text-night-muted">
                        <span class="tnum font-medium text-ink dark:text-night-ink">{{ __('dashboard.agents_compare.messages', ['count' => $agent['messages']]) }}</span>
                        · {{ __('dashboard.agents_compare.leads', ['count' => $agent['leads']]) }}
                    </span>
                </div>
                <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-black/5 dark:bg-white/10">
                    {{-- A share of the busiest agent, not of the total: the
                         question is "who is doing the most", and against a
                         total every agent looks idle once there are five. --}}
                    <div class="h-full rounded-full bg-brand transition-all duration-slow ease-out"
                        style="width: {{ $agent['messages'] === 0 ? 0 : max(2, round($agent['messages'] / $peak * 100)) }}%"></div>
                </div>
            </div>
        @endforeach
    </div>
</div>
