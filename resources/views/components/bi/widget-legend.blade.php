@props([
    // [['alias' => 'm0', 'label' => 'Выручка', 'class' => 'series-1'], …]
    'entries' => [],
])

{{-- The legend.

     PRESENT WHENEVER THERE IS MORE THAN ONE SERIES, and absent when there is
     exactly one — a single-series chart is named by its own card title, and a
     legend box repeating that title is furniture. Above two series the legend
     is what stops identity from being carried by colour alone, which is the
     accessibility floor: a reader who cannot separate two of these hues still
     has the marks direct-labelled on the axis and the names listed here.

     The SWATCH wears the series colour; the TEXT does not. Coloured label text
     is the most common way a chart quietly drops below contrast — the hues are
     validated against the surface for a 3px mark, not for 12px type. --}}
@if (count($entries) > 1)
    <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1.5">
        @foreach ($entries as $entry)
            <span class="flex items-center gap-1.5">
                <span class="series-swatch {{ $entry['class'] }}" aria-hidden="true"></span>
                <span class="text-xs text-muted dark:text-night-muted">{{ $entry['label'] }}</span>
            </span>
        @endforeach
    </div>
@endif
