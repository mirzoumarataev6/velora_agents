{{--
    Where every заявка currently sits.

    A stacked bar rather than a donut. The question is "how much of the pile is
    still mine to deal with", which is a comparison of lengths — and lengths are
    the one thing a donut makes hard. Built from divs and a flex row, same as
    every other chart here.

    Zero-count statuses stay in the legend. "Ждут согласования цены: 0" tells
    the owner nobody is waiting on them, which is worth knowing; dropping the
    row would just make the list look shorter.

    Expects: $metrics.
--}}
@php
    $leads = $metrics['leads_by_status'];
    $rows = $leads['rows'];

    $tints = [
        'new' => 'bg-brand',
        'in_progress' => 'bg-brand/65',
        'awaiting_price_approval' => 'bg-warning',
        'done' => 'bg-success',
    ];
@endphp

<div class="flex h-full flex-col rounded-2xl border border-black/10 bg-white p-5 dark:border-white/10 dark:bg-white/5">
    <h2 class="text-base font-semibold">{{ __('dashboard.leads_status.title') }}</h2>
    <p class="mt-1 text-xs text-muted dark:text-night-muted">{{ __('dashboard.leads_status.description') }}</p>

    @if ($leads['total'] === 0)
        <p class="mt-8 flex-1 text-center text-sm text-muted dark:text-night-muted">{{ __('dashboard.leads_status.empty') }}</p>
    @else
        <div class="mt-5 flex items-baseline gap-2">
            <span class="font-display text-3xl leading-none" data-countup="{{ $leads['total'] }}">{{ $leads['total'] }}</span>
            <span class="text-xs text-muted dark:text-night-muted">{{ __('dashboard.leads_status.total') }}</span>
        </div>

        {{-- One bar, split by share. Segments below 1.5% still get 1.5% so a
             single lead in a pile of two hundred is visible rather than
             mathematically present. --}}
        <div class="mt-4 flex h-3 w-full overflow-hidden rounded-full bg-black/5 dark:bg-white/10">
            @foreach ($rows as $row)
                @if ($row['count'] > 0)
                    <div class="{{ $tints[$row['status']] }} transition-all duration-slow ease-out"
                        style="width: {{ max(1.5, $row['share']) }}%"
                        title="{{ __('dashboard.leads_status.statuses.'.$row['status']) }} — {{ $row['count'] }}"></div>
                @endif
            @endforeach
        </div>

        <ul class="mt-4 space-y-2">
            @foreach ($rows as $row)
                <li class="flex items-center gap-2.5 text-sm">
                    <span class="h-2.5 w-2.5 shrink-0 rounded-sm {{ $tints[$row['status']] }} {{ $row['count'] === 0 ? 'opacity-30' : '' }}"></span>
                    <span class="min-w-0 flex-1 truncate {{ $row['count'] === 0 ? 'text-muted dark:text-night-muted' : '' }}">
                        {{ __('dashboard.leads_status.statuses.'.$row['status']) }}
                    </span>
                    <span class="shrink-0 tnum font-medium">{{ $row['count'] }}</span>
                    <span class="w-12 shrink-0 text-right text-[11px] tnum text-muted dark:text-night-muted">{{ $row['share'] }}%</span>
                </li>
            @endforeach
        </ul>
    @endif
</div>
