{{--
    Shared stroke icons for the Projects views — same visual language as the
    sidebar's inline SVGs in layouts/dashboard.blade.php (currentColor stroke,
    1.8 width, 24x24 viewBox), so a due date or a close button reads as part
    of the product instead of a platform-dependent Unicode glyph. Deliberately
    only the marks this section needs; extend the match on this list rather
    than starting a second icon system elsewhere.
--}}
@props(['name', 'size' => 'h-4 w-4'])

@php
    $paths = [
        'calendar' => '<rect x="3" y="4" width="18" height="17" rx="2"/><path stroke-linecap="round" d="M8 2v4M16 2v4M3 10h18"/>',
        'checklist' => '<path stroke-linecap="round" stroke-linejoin="round" d="M4 6.5l1.4 1.4L8 5m3 1h9M4 12.5l1.4 1.4L8 11m3 1h9M4 18.5l1.4 1.4L8 17m3 1h9"/>',
        'close' => '<path stroke-linecap="round" stroke-linejoin="round" d="M6 6l12 12M18 6L6 18"/>',
        'arrow-left' => '<path stroke-linecap="round" stroke-linejoin="round" d="M19 12H5M11 5l-7 7 7 7"/>',
        'arrow-right' => '<path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M13 5l7 7-7 7"/>',
        'plus' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14M5 12h14"/>',
        'check' => '<path stroke-linecap="round" stroke-linejoin="round" d="M5 12.5l4.5 4.5L19 7"/>',
        'square' => '<rect x="4" y="4" width="16" height="16" rx="3"/>',
    ];
@endphp
<svg class="{{ $size }} shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">{!! $paths[$name] ?? '' !!}</svg>
