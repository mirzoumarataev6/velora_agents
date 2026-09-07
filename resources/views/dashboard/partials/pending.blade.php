{{--
    The two queues that want a person, each linking straight to its own filtered
    view rather than to the leads page in general — an owner who clicks a count
    expects to land on those rows, not to filter for them again by hand.

    Expects: $metrics.
--}}
@php $pending = $metrics['pending']; @endphp

<div class="flex h-full flex-col rounded-2xl border border-black/10 bg-white p-5 dark:border-white/10 dark:bg-white/5">
    <h2 class="text-base font-semibold">{{ __('dashboard.pending.title') }}</h2>

    @if ($pending['open_orders'] === 0 && $pending['price_approvals'] === 0)
        <div class="flex flex-1 flex-col items-center justify-center py-8 text-center">
            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-success/10 text-success dark:text-success-light">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
            </div>
            <p class="mt-3 text-sm text-muted dark:text-night-muted">{{ __('dashboard.pending.clear') }}</p>
        </div>
    @else
        <div class="mt-4 space-y-3">
            <a href="{{ route('leads.index', ['status' => 'new']) }}"
                class="group flex items-center justify-between gap-3 rounded-xl border border-black/10 p-3.5 transition-colors hover:border-brand/30 hover:bg-brand/5 dark:border-white/10">
                <div class="min-w-0">
                    <p class="text-sm font-medium">{{ __('dashboard.pending.open_orders') }}</p>
                    <p class="mt-0.5 text-xs text-muted dark:text-night-muted">{{ __('dashboard.pending.open_orders_hint') }}</p>
                </div>
                <span class="font-display text-2xl leading-none text-brand">{{ $pending['open_orders'] }}</span>
            </a>

            {{-- Warning-toned rather than accent-toned, and deliberately so: a
                 customer is sitting in a live chat waiting on this one, which is
                 not the same kind of "todo" as a lead to ring back. --}}
            <a href="{{ route('leads.index', ['view' => 'haggle', 'status' => \App\Services\Haggling\HagglingService::STATUS_PENDING]) }}"
                class="group flex items-center justify-between gap-3 rounded-xl border p-3.5 transition-colors {{ $pending['price_approvals'] > 0
                    ? 'border-warning/40 bg-warning/[0.04] hover:bg-warning/10'
                    : 'border-black/10 hover:border-brand/30 hover:bg-brand/5 dark:border-white/10' }}">
                <div class="min-w-0">
                    <p class="text-sm font-medium">{{ __('dashboard.pending.price_approvals') }}</p>
                    <p class="mt-0.5 text-xs text-muted dark:text-night-muted">{{ __('dashboard.pending.price_approvals_hint') }}</p>
                </div>
                <span class="font-display text-2xl leading-none {{ $pending['price_approvals'] > 0 ? 'text-warning dark:text-warning-light' : 'text-muted dark:text-night-muted' }}">
                    {{ $pending['price_approvals'] }}
                </span>
            </a>
        </div>
    @endif
</div>
