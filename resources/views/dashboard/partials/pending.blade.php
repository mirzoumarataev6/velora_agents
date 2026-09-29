{{--
    "Требует внимания" — the queues that want a person.

    WHAT THIS REPLACED: two tall tiles in a card that was otherwise white
    space, and which disappeared entirely into a centred tick when both queues
    were clear — a full-height card saying nothing.

    Now it is a list of rows with the same anatomy as every other list in the
    product (icon circle · title · one-line description · count · chevron), and
    it is never empty: the clear state is one compact line, and the rows that
    remain are joined by the two standing shortcuts an owner reaches for from
    here anyway. A card that always has four rows in it is a card that never
    looks broken.

    Price negotiations keep the amber treatment, and it is the only thing here
    that gets it: a customer is sitting in a live chat waiting on a number,
    which is a different kind of queue from a lead to ring back.

    Each row links straight to its own filtered view rather than to the leads
    page in general — an owner who clicks a count expects to land on those
    rows, not to filter for them again by hand.

    Expects: $metrics.
--}}
@php
    $pending = $metrics['pending'];
    $statuses = collect($metrics['leads_by_status']['rows'] ?? [])->keyBy('status');
    $ratings = $metrics['ratings'];

    $allClear = $pending['open_orders'] === 0 && $pending['price_approvals'] === 0;
@endphp

<div class="card card-lift flex h-full flex-col p-5">
    <h2 class="section-head-title">{{ __('dashboard.pending.title') }}</h2>

    <div class="mt-3 flex-1 space-y-1">
        @if ($allClear)
            {{-- Compact, not a full-height celebration. Nothing is wrong, so
                 nothing should take up the room that a problem would. --}}
            <div class="empty-inline">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="!text-success dark:!text-success-light" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                <span>{{ __('dashboard.pending.clear') }}</span>
            </div>
        @endif

        {{-- Price approvals first when there are any: it is the only row here
             with a customer actively waiting at the other end. --}}
        @if ($pending['price_approvals'] > 0)
            <a href="{{ route('leads.index', ['view' => 'haggle', 'status' => \App\Services\Haggling\HagglingService::STATUS_PENDING]) }}"
                class="attn-row attn-row-warn">
                <span class="icon-circle icon-circle-sm icon-circle-warning" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </span>
                <span class="min-w-0 flex-1">
                    <span class="list-row-title block">{{ __('dashboard.pending.price_approvals') }}</span>
                    <span class="list-row-sub block">{{ __('dashboard.pending.price_approvals_hint') }}</span>
                </span>
                <span class="attn-count text-warning dark:text-warning-light">{{ $pending['price_approvals'] }}</span>
                <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
            </a>
        @endif

        @if ($pending['open_orders'] > 0)
            <a href="{{ route('leads.index', ['status' => 'new']) }}" class="attn-row">
                <span class="icon-circle icon-circle-sm" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-6 0h.01M12 16h3m-6 0h.01"/></svg>
                </span>
                <span class="min-w-0 flex-1">
                    <span class="list-row-title block">{{ __('dashboard.pending.open_orders') }}</span>
                    <span class="list-row-sub block">{{ __('dashboard.pending.open_orders_hint') }}</span>
                </span>
                <span class="attn-count text-brand-strong dark:text-brand-light">{{ $pending['open_orders'] }}</span>
                <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
            </a>
        @endif

        {{-- Negative ratings. Not a "queue" in the workflow sense, which is why
             it was never here — but it is the other thing on this dashboard
             that means an answer went wrong and a person should look. It only
             appears when there is something to look at. --}}
        @if (($ratings['down'] ?? 0) > 0)
            <a href="{{ route('conversations.index', ['status' => 'rated_down']) }}" class="attn-row">
                <span class="icon-circle icon-circle-sm icon-circle-danger" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M10 14H5.236a2 2 0 01-1.789-2.894l3.5-7A2 2 0 018.736 3h4.018a2 2 0 01.485.06l3.76.94m-7 10v5a2 2 0 002 2h.096c.5 0 .905-.405.905-.904 0-.715.211-1.413.608-2.008L17 13V4m-7 10h2m5-10h2a2 2 0 012 2v6a2 2 0 01-2 2h-2.5"/></svg>
                </span>
                <span class="min-w-0 flex-1">
                    <span class="list-row-title block">{{ __('dashboard.pending.negative_ratings') }}</span>
                    <span class="list-row-sub block">{{ __('dashboard.pending.negative_ratings_hint') }}</span>
                </span>
                <span class="attn-count text-danger dark:text-danger-light">{{ $ratings['down'] }}</span>
                <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
            </a>
        @endif

        {{-- Leads in progress. Always shown, including at zero: it is the
             standing "what am I working on" shortcut, and it is what keeps
             this card from collapsing on a quiet day. --}}
        <a href="{{ route('leads.index', ['status' => 'in_progress']) }}" class="attn-row">
            <span class="icon-circle icon-circle-sm icon-circle-neutral" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 2m6-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </span>
            <span class="min-w-0 flex-1">
                <span class="list-row-title block">{{ __('dashboard.pending.in_progress') }}</span>
                <span class="list-row-sub block">{{ __('dashboard.pending.in_progress_hint') }}</span>
            </span>
            <span class="attn-count text-ink dark:text-night-ink">{{ $statuses['in_progress']['count'] ?? 0 }}</span>
            <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
        </a>
    </div>

    <a href="{{ route('leads.index') }}" class="section-link rule mt-3 pt-3">
        {{ __('dashboard.pending.view_all') }}
        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
    </a>
</div>
