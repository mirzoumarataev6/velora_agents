@props([
    // Raw daily values, oldest first.
    'values' => [],
    // 'brand' (default) or 'warn' — a metric that is going the wrong way.
    'tone' => 'brand',
    'height' => 32,
])

@include('partials.chart-helpers')

@php
    $values = array_values(array_map('floatval', $values));

    /* A sparkline of one point is a dot, and a sparkline of none is nothing.
       Either way there is no shape to read, so the component renders empty and
       the card's layout closes up around it rather than reserving a blank
       strip. */
    $enough = count($values) >= 2;

    /* The viewBox is unitless and the SVG is stretched to the card's width by
       CSS, so these numbers are a drawing grid rather than pixels. 1px of
       padding keeps the stroke's own width from being clipped at the extremes. */
    $w = 100.0;
    $h = 28.0;
    $points = $enough ? velora_chart_points($values, $w, $h, null, 1.5) : [];

    /* Every gradient on a page needs its own id, or the second sparkline
       inherits the first one's fill. */
    $gradientId = 'spark-'.\Illuminate\Support\Str::random(6);
@endphp

@if ($enough)
    {{-- The accent is set on the SVG ROOT, not on an inner <g>. A gradient
         stop's `currentColor` resolves against the colour the <defs> block
         itself inherits, never against the element that references the
         gradient — put the class on a group and the fill silently comes out
         black-on-nothing. --}}
    <svg class="sparkline {{ $tone === 'warn' ? 'sparkline-warn text-warning dark:text-warning-light' : 'text-brand dark:text-brand-light' }}"
        style="height: {{ $height }}px"
        viewBox="0 0 {{ $w }} {{ $h }}"
        preserveAspectRatio="none"
        role="presentation"
        aria-hidden="true"
        {{ $attributes }}>
        <defs>
            <linearGradient id="{{ $gradientId }}" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" stop-color="currentColor" stop-opacity="0.22" />
                <stop offset="100%" stop-color="currentColor" stop-opacity="0" />
            </linearGradient>
        </defs>
        <path class="spark-area" fill="url(#{{ $gradientId }})" d="{{ velora_chart_area($points, $h) }}" />
        <path class="spark-line" d="{{ velora_chart_line($points) }}" />
    </svg>
@endif
