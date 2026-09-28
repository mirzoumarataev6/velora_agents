{{--
    One headline number.

    The hint is a title attribute rather than a third line of text: five of these
    sit in a row, and a definition under each one turns a scannable strip into a
    paragraph. The number is the content; the label names it; the definition is
    there when someone asks.

    Expects: $label, $value. Optional: $hint, $countTo, $live.
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
@endphp

<div @if (!empty($hint)) title="{{ $hint }}" @endif
    class="stat-card rounded-2xl border border-black/10 bg-white p-4 transition-shadow hover:shadow-e2 dark:border-white/10 dark:bg-white/5">
    <p class="flex items-center gap-1.5 text-xs font-medium text-muted dark:text-night-muted">
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
    <p class="mt-2 font-display text-3xl leading-none tnum"
        @if ($countTo !== null) data-countup="{{ $countTo }}" @endif>{{ $display }}</p>
</div>
