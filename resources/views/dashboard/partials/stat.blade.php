{{--
    One headline number — the full anatomy.

    The previous version of this file was a label and a number on a large white
    rectangle, five across. That is what made the dashboard read as empty: each
    tile spent its whole area saying one word and one digit, and four of the
    five left two thirds of their height blank.

    A tile is now, top to bottom:
        · an icon in a light-blue circle, so the row is scannable by shape
        · the small grey label (with the live dot, where the figure is "now")
        · the figure and, beside it, a trend pill
        · EITHER a sparkline of the same metric across the period,
          OR a one-line context fact — never nothing.

    On the trend/sparkline split: only two of the five headline metrics have a
    daily series in the payload (messages, and average response time), and this
    redesign adds no queries. The other three get a genuine second fact instead
    of an invented sparkline — "12 из 55 диалогов" is worth more than a
    decorative squiggle, and a fabricated trend on a metric with no history
    would be the one thing on this page a reader could catch out.

    Expects: $label, $value.
    Optional: $hint, $countTo, $live, $icon, $series, $trend, $trendTone,
              $context, $sparkTone.
--}}
@php
    // Only a plain number counts up. "—" and "1,4 с" have nothing to count
    // toward, and animating them would mean parsing display strings back into
    // numbers — which is how a dash becomes a zero.
    $countTo = $countTo ?? (is_int($value) ? $value : null);

    // The number shown to a reader gets a thousands separator; the number
    // handed to the count-up animation stays a bare integer, since it is
    // parsed back with Number() in app.js. countUpNumbers() restores this
    // exact formatted string once the animation ends, so the two never
    // disagree — see resources/js/app.js.
    $display = is_int($value) ? number_format($value, 0, '.', ' ') : $value;

    $series = $series ?? [];
    $trend = $trend ?? null;

    /* Direction is not quality. A falling response time is a win and a falling
       lead count is not, so the call site passes `$trendTone`:
         'higher-better' (default) — up is green, down is amber
         'lower-better'            — the reverse
       The arrow always tells the truth about direction either way; only the
       colour changes. That is what keeps the pill honest in greyscale. */
    $trendTone = $trendTone ?? 'higher-better';

    $trendClass = 'trend-flat';
    if ($trend !== null && $trend['direction'] !== 'flat') {
        $good = $trendTone === 'lower-better'
            ? $trend['direction'] === 'down'
            : $trend['direction'] === 'up';
        // `trend-up`/`trend-down` supply the arrow; the tone class supplies the
        // colour. They are separate so a "good down" keeps its down arrow.
        $trendClass = ($trend['direction'] === 'up' ? 'trend-up' : 'trend-down').' '.($good ? 'trend-good' : 'trend-bad');
    }

    /* The icon set, inline rather than a component: each is one path, and a
       lookup here keeps the call sites naming a concept ('leads') instead of
       pasting geometry. */
    $icons = [
        'messages' => 'M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8-1.06 0-2.08-.16-3.02-.46L3 21l1.5-4.5C3.55 15.1 3 13.6 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z',
        'chat' => 'M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z',
        'leads' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-6 0h.01M12 16h3m-6 0h.01',
        'conversion' => 'M3 17l6-6 4 4 8-8m0 0h-5m5 0v5',
        'clock' => 'M12 8v4l3 2m6-2a9 9 0 11-18 0 9 9 0 0118 0z',
    ];
    $iconPath = $icons[$icon ?? 'messages'] ?? $icons['messages'];
@endphp

<div @if (!empty($hint)) title="{{ $hint }}" @endif
    class="card stat-card card-lift flex flex-col p-4">
    <div class="flex items-start justify-between gap-2">
        <span class="icon-circle icon-circle-sm" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                <path stroke-linecap="round" stroke-linejoin="round" d="{{ $iconPath }}" />
            </svg>
        </span>

        @if ($trend !== null)
            {{-- The pill's own text is the percentage; `aria-label` spells the
                 direction out, because "↑" is a decorative glyph to a screen
                 reader and "18%" on its own says nothing. --}}
            <span class="{{ $trendClass }}"
                aria-label="{{ __('dashboard.trend.'.$trend['direction'], ['percent' => $trend['percent']]) }}">
                {{ $trend['percent'] }}%
            </span>
        @endif
    </div>

    <p class="kpi-label mt-3 flex items-center gap-1.5">
        {{-- A slow pulse on the one number that is TRUE RIGHT NOW rather than
             true of the period. It marks the card as live; it is not decoration
             on every tile. --}}
        @if (!empty($live))
            <span class="relative flex h-1.5 w-1.5 shrink-0" aria-hidden="true">
                <span class="pulse-ring absolute inline-flex h-full w-full rounded-full bg-brand opacity-60"></span>
                <span class="relative inline-flex h-1.5 w-1.5 rounded-full bg-brand"></span>
            </span>
        @endif
        {{ $label }}
    </p>

    <p class="kpi-value mt-1"
        @if ($countTo !== null) data-countup="{{ $countTo }}" @endif>{{ $display }}</p>

    {{-- The bottom slot. `mt-auto` pins it to the card's floor so five tiles
         of different content still line their sparklines up with each other. --}}
    <div class="mt-auto pt-3">
        @if (count($series) >= 2)
            <x-sparkline :values="$series" :tone="$sparkTone ?? 'brand'" :height="30" />
        @elseif (!empty($context))
            <p class="text-xs text-muted dark:text-night-muted">{{ $context }}</p>
        @endif
    </div>
</div>
