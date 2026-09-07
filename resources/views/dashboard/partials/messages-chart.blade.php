{{--
    Messages per day, stacked by channel.

    Drawn as div heights rather than with a charting library: the design
    constraint is HTML + Tailwind only, and a bar chart is one of the few chart
    types that loses nothing to it. The stack only appears when more than one
    channel is connected — with a single channel a "breakdown" is a solid bar
    wearing a legend.

    Expects: $metrics.
--}}
@php
    $series = $metrics['series'];
    $range = $metrics['range'];
    $channels = $metrics['channels'];
    $peak = max(1, max(array_column($series, 'total')));
    $periodTotal = array_sum(array_column($series, 'total'));

    // A channel is only worth colouring separately when there is something to
    // compare it against.
    $stacked = count($channels) > 1;

    // Tints off the one accent rather than new hues — the palette has a single
    // accent by design, and a second colour here would be inventing one.
    $channelTint = [
        'telegram' => 'bg-brand',
        'whatsapp' => 'bg-brand/70',
        'instagram' => 'bg-brand/45',
        'playground' => 'bg-brand/25',
    ];

    // 30 columns in the same width as 7 need to lose their gaps and labels.
    $dense = $range > 7;

    // Bars are sized in rem against a track of this exact height, not in
    // percent. A percentage height only resolves against a parent with a
    // definite height, and a flex item under `items-end` is sized by its
    // content — so the percentages the first version emitted resolved to auto
    // and every bar collapsed to nothing.
    $trackRem = 10;
@endphp

<div class="flex h-full flex-col rounded-2xl border border-black/10 bg-white p-5 dark:border-white/10 dark:bg-white/5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="text-base font-semibold">{{ __('dashboard.messages_chart.title') }}</h2>
            <p class="mt-0.5 text-xs text-muted dark:text-night-muted">
                {{ __('dashboard.messages_chart.total') }}: <span class="font-semibold text-ink dark:text-night-ink">{{ $periodTotal }}</span>
            </p>
        </div>

        {{-- Plain links, not a JS toggle: the range belongs in the URL so it
             survives a reload and can be linked to, and the page is one query
             either way. --}}
        <div class="flex rounded-full border border-black/10 p-0.5 dark:border-white/10">
            @foreach (\App\Services\Analytics\DashboardMetrics::RANGES as $option)
                <a href="{{ route('dashboard', ['range' => $option]) }}"
                    class="rounded-full px-3 py-1 text-xs font-semibold transition-colors {{ $range === $option ? 'bg-brand/10 text-brand' : 'text-muted hover:text-ink dark:text-night-muted dark:hover:text-night-ink' }}">
                    {{ __('dashboard.messages_chart.range_'.$option) }}
                </a>
            @endforeach
        </div>
    </div>

    @if ($periodTotal === 0)
        <p class="mt-8 flex-1 text-center text-sm text-muted dark:text-night-muted">{{ __('dashboard.messages_chart.empty') }}</p>
    @else
        <div class="mt-5 flex items-end {{ $dense ? 'gap-0.5' : 'gap-1.5' }}">
            @foreach ($series as $day)
                @php
                    // A day with a single message against a peak of 200 still
                    // has to be visible, so bars carry a floor rather than
                    // rounding themselves away.
                    $height = $day['total'] === 0 ? 0 : max(0.25, round($day['total'] / $peak * $trackRem, 3));
                @endphp
                <div class="group flex flex-1 flex-col items-center gap-1.5"
                    title="{{ $day['label'] }} — {{ $day['total'] }}">
                    {{-- The track is what gives the bar a definite height to
                         be a fraction of; without it the bar has nothing to
                         measure itself against. --}}
                    <div class="flex w-full flex-col justify-end" style="height: {{ $trackRem }}rem">
                        <div class="flex w-full flex-col-reverse overflow-hidden rounded-t-md transition-opacity group-hover:opacity-80"
                            style="height: {{ $height }}rem">
                            @if ($stacked)
                                @foreach ($channels as $channel)
                                    @php $count = $day['by_channel'][$channel] ?? 0; @endphp
                                    @if ($count > 0)
                                        <div class="{{ $channelTint[$channel] ?? 'bg-brand/25' }}"
                                            style="height: {{ round($count / max(1, $day['total']) * 100, 2) }}%"></div>
                                    @endif
                                @endforeach
                            @else
                                <div class="h-full bg-brand"></div>
                            @endif
                        </div>
                    </div>
                    @unless ($dense)
                        <span class="text-[10px] text-muted dark:text-night-muted">{{ $day['label'] }}</span>
                    @endunless
                </div>
            @endforeach
        </div>

        @if ($stacked)
            <div class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-1.5 border-t border-black/5 pt-3 dark:border-white/10">
                @foreach ($channels as $channel)
                    <span class="flex items-center gap-1.5 text-xs text-muted dark:text-night-muted">
                        <span class="h-2.5 w-2.5 rounded-sm {{ $channelTint[$channel] ?? 'bg-brand/25' }}"></span>
                        {{ __('dashboard.channels.'.$channel) }}
                    </span>
                @endforeach
            </div>
        @endif
    @endif
</div>
