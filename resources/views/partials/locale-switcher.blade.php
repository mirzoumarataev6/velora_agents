@php
    $__switcherSegments = request()->segments();
    $__flags = ['ru' => '🇷🇺', 'tg' => '🇹🇯', 'en' => '🇬🇧'];
    // When true (default: auth pages), each item carries data-locale so the
    // i18n engine intercepts the click and swaps in place. When false (the
    // landing page), the items are plain <a href> links that navigate normally
    // — a full page reload, which the heavy hero/Swiper/scroll visuals prefer.
    // The chosen locale still persists: the /{locale}/… navigation runs the
    // SetLocale middleware, which writes it to the session (and user record).
    $instantSwitch = $instantSwitch ?? true;
@endphp
<details class="locale-switcher group relative">
    <summary
        class="flex cursor-pointer list-none items-center gap-1.5 rounded-full border border-black/10 bg-white/70 px-3 py-1.5 text-sm font-medium text-ink backdrop-blur transition-colors hover:border-black/20 dark:border-white/10 dark:bg-white/10 dark:text-white [&::-webkit-details-marker]:hidden">
        <span aria-hidden="true">{{ $__flags[app()->getLocale()] ?? '🌐' }}</span>
        <span>{{ __('nav.locales.'.app()->getLocale()) }}</span>
        <svg class="h-3.5 w-3.5 text-muted transition-transform group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6"/></svg>
    </summary>
    <div class="absolute right-0 z-50 mt-2 w-40 overflow-hidden rounded-2xl border border-black/10 bg-white py-1 shadow-xl dark:border-white/10 dark:bg-night-card">
        @foreach (['ru', 'tg', 'en'] as $loc)
            @php
                $segs = $__switcherSegments;
                $segs[0] = $loc;
                $href = '/'.implode('/', $segs);
            @endphp
            <a href="{{ $href }}"
                @if ($instantSwitch) data-locale-link data-locale="{{ $loc }}" aria-current="{{ $loc === app()->getLocale() ? 'true' : 'false' }}" @endif
                class="flex items-center gap-2 px-4 py-2 text-sm transition-colors hover:bg-paper dark:hover:bg-white/5 {{ $loc === app()->getLocale() ? 'font-semibold text-brand' : 'text-ink dark:text-white/80' }}">
                <span aria-hidden="true">{{ $__flags[$loc] }}</span> {{ __('nav.locales.'.$loc) }}
            </a>
        @endforeach
    </div>
</details>
<script>
    document.addEventListener('click', (event) => {
        document.querySelectorAll('details.locale-switcher[open]').forEach((el) => {
            if (!el.contains(event.target)) el.removeAttribute('open');
        });
    });
</script>
