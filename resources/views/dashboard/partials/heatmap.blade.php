{{--
    Day-of-week × hour-of-day message volume, GitHub-contributions style.

    The intensity is the accent at varying opacity rather than a multi-colour
    ramp: the palette ships one accent on purpose, and a green-to-red scale here
    would read as good-to-bad when the axis is only busy-to-quiet.

    Five steps, not a continuous alpha — a continuous ramp makes 3 and 4 messages
    indistinguishable, and the point of the grid is to spot the busy block at a
    glance. Zero is left as the empty surface so quiet hours read as absence.

    Expects: $metrics.
--}}
@php
    $heatmap = $metrics['heatmap'];
    $days = __('dashboard.heatmap.days');
    $max = $heatmap['max'];

    // Opacity steps, coarse enough to be legible against each other.
    $steps = ['bg-black/[0.04] dark:bg-white/[0.06]', 'bg-brand/20', 'bg-brand/40', 'bg-brand/65', 'bg-brand'];

    $level = function (int $count) use ($max) {
        if ($count === 0) {
            return 0;
        }

        // Quartiles of the busiest cell, so the scale adapts to a shop doing ten
        // messages a week and one doing ten thousand.
        return (int) min(4, 1 + floor(($count - 1) / max(1, $max) * 4));
    };
@endphp

<div class="rounded-2xl border border-black/10 bg-white p-5 dark:border-white/10 dark:bg-white/5">
    <h2 class="text-base font-semibold">{{ __('dashboard.heatmap.title') }}</h2>
    <p class="mt-0.5 text-xs text-muted dark:text-night-muted">{{ __('dashboard.heatmap.description') }}</p>

    {{-- Scrolls inside its own box on a phone rather than pushing the page
         sideways: 24 columns will not fit a narrow screen at a legible size. --}}
    <div class="mt-4 overflow-x-auto">
        <div class="min-w-[34rem]">
            <div class="space-y-1">
                @foreach ($heatmap['grid'] as $dayIndex => $hours)
                    <div class="flex items-center gap-1">
                        <span class="w-7 shrink-0 text-[10px] text-muted dark:text-night-muted">{{ $days[$dayIndex] ?? '' }}</span>
                        @foreach ($hours as $hour => $count)
                            <div class="h-4 flex-1 rounded-[3px] {{ $steps[$level($count)] }}"
                                title="{{ __('dashboard.heatmap.cell', [
                                    'day' => $days[$dayIndex] ?? '',
                                    'hour' => str_pad((string) $hour, 2, '0', STR_PAD_LEFT),
                                    'count' => $count,
                                ]) }}"></div>
                        @endforeach
                    </div>
                @endforeach
            </div>

            <div class="mt-2 flex items-center gap-1 pl-8">
                @foreach ([0, 6, 12, 18] as $tick)
                    <span class="flex-1 text-[10px] text-muted dark:text-night-muted">{{ str_pad((string) $tick, 2, '0', STR_PAD_LEFT) }}:00</span>
                @endforeach
            </div>
        </div>
    </div>

    <div class="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-black/5 pt-3 dark:border-white/10">
        {{-- The hour columns are already converted to this timezone (see
             DashboardMetrics::heatmap() / local()) — this just says so, so an
             owner comparing it to their own clock isn't left guessing which
             clock the grid is drawn on. --}}
        <span class="text-[10px] text-muted dark:text-night-muted">{{ __('dashboard.heatmap.timezone_label') }}</span>

        <span class="flex items-center gap-1.5">
            <span class="text-[10px] text-muted dark:text-night-muted">{{ __('dashboard.heatmap.less') }}</span>
            @foreach ($steps as $step)
                <span class="h-3 w-3 rounded-[3px] {{ $step }}"></span>
            @endforeach
            <span class="text-[10px] text-muted dark:text-night-muted">{{ __('dashboard.heatmap.more') }}</span>
        </span>
    </div>
</div>
