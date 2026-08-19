@php
    $__flags = ['ru' => '🇷🇺', 'tg' => '🇹🇯', 'en' => '🇬🇧'];
    $__locales = ['ru', 'tg', 'en'];
@endphp
{{--
    The sidebar sets both `backdrop-blur-xl` and a translate-x transition. Each of
    those creates a containing block AND a stacking context, so an absolutely
    positioned menu inside it can never escape the sidebar's bounds or its z-index —
    no amount of z-50 helps. The menu is therefore teleported to <body> and given
    viewport-fixed coordinates measured from the trigger.
--}}
<div x-data="localeSwitcher()" class="shrink-0">
    <button type="button" x-ref="trigger" @click.stop="toggle()" @keydown.down.prevent="open ? focusItem(0) : toggle()"
        :aria-expanded="open ? 'true' : 'false'" aria-haspopup="menu"
        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm transition-colors hover:bg-black/5 dark:hover:bg-white/10"
        aria-label="{{ __('nav.locale_switcher_label') }}">
        <span aria-hidden="true">{{ $__flags[app()->getLocale()] ?? '🌐' }}</span>
    </button>

    <template x-teleport="body">
        <div x-show="open" x-cloak x-ref="menu" :style="style" role="menu" tabindex="-1"
            @click.outside="close(false)"
            @keydown.escape.prevent.stop="close(true)"
            @keydown.tab="close(false)"
            @keydown.down.prevent="focusItem(index + 1)"
            @keydown.up.prevent="focusItem(index - 1)"
            x-transition:enter="transition ease-out duration-150"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-100"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95"
            class="fixed z-[110] w-40 overflow-hidden rounded-2xl border border-black/10 bg-white py-1 shadow-xl dark:border-white/10 dark:bg-night-card">
            @foreach ($__locales as $loc)
                <form method="POST" action="{{ route('locale.update') }}">
                    @csrf
                    <input type="hidden" name="locale" value="{{ $loc }}" />
                    <button type="submit" role="menuitem" x-ref="item{{ $loop->index }}"
                        data-locale="{{ $loc }}" aria-current="{{ $loc === app()->getLocale() ? 'true' : 'false' }}"
                        class="flex w-full items-center gap-2 px-4 py-2 text-left text-sm transition-colors hover:bg-paper focus:bg-paper focus:outline-none dark:hover:bg-white/5 dark:focus:bg-white/5 {{ $loc === app()->getLocale() ? 'font-semibold text-brand' : 'text-ink dark:text-white/80' }}">
                        <span aria-hidden="true">{{ $__flags[$loc] }}</span> {{ __('nav.locales.'.$loc) }}
                    </button>
                </form>
            @endforeach
        </div>
    </template>
</div>

@once
@push('scripts')
<script>
    function localeSwitcher() {
        return {
            open: false,
            style: '',
            index: 0,
            count: {{ count($__locales) }},

            toggle() {
                this.open ? this.close(true) : this.show();
            },

            show() {
                this.open = true;
                this.index = 0;
                this.$nextTick(() => {
                    this.position();
                    this.focusItem(0);
                });
            },

            close(refocus) {
                if (!this.open) return;
                this.open = false;
                if (refocus) this.$refs.trigger?.focus();
            },

            /**
             * Fixed coordinates measured from the trigger. The switcher sits in the
             * sidebar footer, so the menu opens upward unless there genuinely isn't
             * room above it.
             */
            position() {
                const menu = this.$refs.menu;
                const rect = this.$refs.trigger.getBoundingClientRect();
                const gap = 8;
                const height = menu.offsetHeight;
                const width = menu.offsetWidth;

                const openUp = rect.top - gap - height >= 0;
                const top = openUp ? rect.top - gap - height : Math.min(rect.bottom + gap, window.innerHeight - height - gap);
                const left = Math.min(Math.max(gap, rect.right - width), window.innerWidth - width - gap);

                this.style = `top:${Math.max(gap, top)}px; left:${left}px;`;
            },

            focusItem(i) {
                this.index = (i + this.count) % this.count;
                this.$refs['item' + this.index]?.focus();
            },

            init() {
                // Keep the menu pinned to the trigger if the page moves underneath it.
                const reposition = () => this.open && this.position();
                window.addEventListener('scroll', reposition, true);
                window.addEventListener('resize', reposition);
            },
        };
    }
</script>
@endpush
@endonce
