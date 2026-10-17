{{--
    In-app task-assignment notification bell (App\Notifications\TaskAssigned,
    served by GET/POST /notifications/tasks… — TaskNotificationFeed).

    A SEPARATE dropdown next to lead-bell.blade.php, not a "Tasks" section
    merged into it. Three reasons: the lead bell's `count`/`recent`/`bellOpen`
    triple is read by its own existing tests AND by the sidebar's own unread
    pill (layouts/dashboard.blade.php) — touching that panel's markup to
    splice in a second kind of item risked breaking either; the two counts
    answer different questions (new leads to work vs. tasks assigned to YOU),
    and folding them into one badge number would be more confusing, not less;
    and leads.poll's `count`/`recent`/`escalations` shape has nothing in
    common with this feed's `unread`/`items`, so sharing one panel would mean
    two unrelated response shapes feeding one template anyway. Same icon
    button chrome as lead-bell.blade.php (size, radius, hover/focus states,
    badge placement) for visual consistency.

    The DATA (tasksItems/tasksUnread/pollTasks()/markTaskRead()/…) lives on
    the shared `shell` store, same as the lead bell, so there is still
    exactly one poll timer for both (see resources/js/app.js). The dropdown's
    OPEN/CLOSED state and its on-screen POSITION do not: they are local
    x-data on taskBell() below, one instance per bell button, teleported to
    <body> — same reasoning as team-switcher.blade.php's own docblock (the
    sidebar's blur+transform make it the wrong containing block for a fixed
    -position menu) PLUS one this component actually hit in practice: this
    is the SECOND icon in both the sidebar header and the mobile header, so
    a static `left-0`/`right-0` anchor that works for the FIRST icon
    (lead-bell, which always has open space on one side) does not generalise
    — in the ~256px sidebar, right-0 put the panel at a negative x; in a
    ~400px mobile header, left-0 ran it off the right edge. A single shared
    SOURCE OF TRUTH (one `$store.shell.tasksBellOpen` boolean) made it worse,
    not better: opening either instance showed BOTH panels at once, and the
    off-canvas sidebar instance's own copy — width unconstrained by the
    sidebar's own translate distance — still bled back into view. Per
    -instance local state plus a measured, clamped position (same
    rect.right - width, clamped to [gap, innerWidth - width - gap], as
    team-switcher's own position()) fixes both at once.
--}}
{{-- @turbo:before-visit.window: belt-and-suspenders alongside the item
     link's own close(false) below — ANY navigation away (a click on an
     unrelated link elsewhere on the page while this menu happens to be
     open, Esc-to-close-then-back-navigate, …) must close it too, not just
     the one path through this component's own items. --}}
<div x-data="taskBell()" class="relative" @turbo:before-visit.window="close(false)">
    <button type="button" x-ref="trigger" @click="toggle()"
        :aria-expanded="open ? 'true' : 'false'" aria-haspopup="true"
        :aria-label="$store.shell.i18nText('notifications.bell_aria', { count: $store.shell.tasksUnread > 99 ? '99+' : $store.shell.tasksUnread })"
        class="relative flex h-9 w-9 items-center justify-center rounded-full text-muted transition-colors hover:bg-sunk focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand dark:text-night-muted dark:hover:bg-night-raised">
        {{-- A checklist mark, not a second bell: two identical glyphs side by
             side would read as a rendering mistake rather than two distinct
             things. Same stroke convention (currentColor, 1.8, 24x24) as
             every other inline icon in this layout. --}}
        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 11l3 3L22 4M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg>
        <span x-show="$store.shell.tasksUnread > 0" x-cloak x-text="$store.shell.tasksUnread > 99 ? '99+' : $store.shell.tasksUnread"
            class="absolute -right-0.5 -top-0.5 flex h-[18px] min-w-[18px] items-center justify-center rounded-full bg-brand-strong px-1 text-[10px] font-bold leading-none tabular-nums text-white ring-2 ring-white dark:ring-night"></span>
    </button>

    <template x-teleport="body">
        <div x-show="open" x-cloak x-ref="menu" :style="style" role="menu" tabindex="-1"
            :aria-label="$store.shell.i18nText('notifications.title')"
            @click.outside="close(false)"
            @keydown.escape.prevent.stop="close(true)"
            x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 -translate-y-1" x-transition:enter-end="opacity-100 translate-y-0"
            class="card fixed z-[110] w-80 max-w-[calc(100vw-2rem)] overflow-hidden">
            <div class="flex items-center justify-between border-b border-hairline px-4 py-3 dark:border-white/10">
                <span class="text-sm font-semibold" x-text="$store.shell.i18nText('notifications.title')"></span>
                <button type="button" @click="$store.shell.markAllTasksRead()" x-show="$store.shell.tasksUnread > 0"
                    class="text-xs font-medium text-brand-strong hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand"
                    x-text="$store.shell.i18nText('notifications.mark_all_read')"></button>
            </div>

            <div class="max-h-96 overflow-y-auto">
                <template x-if="$store.shell.tasksLoadError">
                    <div class="flex flex-col items-center gap-2 px-4 py-8 text-center">
                        <p class="text-sm text-muted dark:text-night-muted" x-text="$store.shell.i18nText('notifications.load_error')"></p>
                        <button type="button" @click="$store.shell.pollTasks()"
                            class="text-sm font-medium text-brand-strong hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand"
                            x-text="$store.shell.i18nText('notifications.retry')"></button>
                    </div>
                </template>

                <template x-if="!$store.shell.tasksLoadError && $store.shell.tasksItems.length === 0">
                    <div class="flex flex-col items-center gap-2 px-4 py-8 text-center">
                        <svg class="h-8 w-8 text-muted dark:text-night-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 11l3 3L22 4M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg>
                        <p class="text-sm text-muted dark:text-night-muted" x-text="$store.shell.i18nText('notifications.empty')"></p>
                    </div>
                </template>

                <template x-for="item in $store.shell.tasksItems" :key="item.id">
                    {{-- Clicking marks it read (optimistic — see markTaskRead())
                         and navigates with Turbo: a plain <a href>, no
                         preventDefault, so Turbo's own site-wide click
                         interception handles the visit exactly like any other
                         in-app link — the POST never blocks the navigation.
                         close(false) alongside it: the sidebar instance is
                         data-turbo-permanent and survives the visit, but its
                         teleported menu (a child of the OLD <body>) is thrown
                         away with the rest of the page — leaving `open` true
                         with nothing left showing, so the very next click on
                         the bell would only "close" a menu nobody can see. --}}
                    <a :href="item.url" role="menuitem" @click="$store.shell.markTaskRead(item.id); close(false)"
                        class="flex gap-3 border-b border-hairline px-4 py-3 transition-colors last:border-b-0 hover:bg-sunk focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-brand dark:border-white/10 dark:hover:bg-night-raised"
                        :class="!item.read ? 'bg-azure-50 dark:bg-brand/10' : ''">
                        <span class="icon-circle icon-circle-sm shrink-0 text-xs font-semibold" aria-hidden="true" x-text="$store.shell.initialsOf(item.assigned_by_name)"></span>
                        <span class="min-w-0 flex-1">
                            <span class="flex items-start gap-1.5">
                                <span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-brand" x-show="!item.read" aria-hidden="true"></span>
                                {{-- break-words, not truncate: a long Tajik word
                                     (requirement) must wrap rather than overflow
                                     the panel, where a short English title would
                                     just truncate — this sentence can contain
                                     either. --}}
                                <span class="min-w-0 flex-1 break-words text-sm leading-5 text-ink dark:text-night-ink">
                                    <template x-for="(part, idx) in $store.shell.sentenceParts($store.shell.i18nText('notifications.assigned_you'), { name: item.assigned_by_name, task: item.task_title })" :key="idx">
                                        <span :class="part.strong && 'font-medium'" x-text="part.value"></span>
                                    </template>
                                </span>
                            </span>
                            <span class="mt-0.5 block truncate text-xs text-muted dark:text-night-muted" x-text="$store.shell.boardLine(item)"></span>
                            <span class="mt-0.5 block text-xs text-muted dark:text-night-muted" x-text="$store.shell.relativeTime(item.created_at)"></span>
                        </span>
                    </a>
                </template>
            </div>
        </div>
    </template>
</div>

@once
@push('scripts')
<script>
    /**
     * Local to each bell instance (sidebar header, mobile header — see
     * layouts/dashboard.blade.php) — see this partial's own top docblock for
     * why open/style live here rather than on the shared `shell` store.
     * Wrapped in Blade's "once" directive, same as teamSwitcher() in
     * team-switcher.blade.php: this partial is included twice per page, and
     * the function only needs defining once regardless.
     */
    function taskBell() {
        return {
            open: false,
            style: '',

            toggle() {
                this.open ? this.close(true) : this.show();
            },

            show() {
                this.open = true;
                // The moment a stale list (up to 25s old) is most visible —
                // refresh right away rather than make the opener wait for
                // the next tick.
                this.$store.shell.pollTasks();
                this.$nextTick(() => this.position());
            },

            close(refocus) {
                if (!this.open) return;
                this.open = false;
                if (refocus) this.$refs.trigger.focus();
            },

            /**
             * Right-aligned to the trigger by default (rect.right - width),
             * same as this bell reads visually in the header — but clamped
             * into [gap, innerWidth - width - gap] either direction, which
             * is what actually matters: a trigger near the LEFT edge of a
             * narrow sidebar (not enough room to its left for a right
             * -aligned panel) clamps up to `gap`; a trigger flush against
             * the RIGHT edge of a ~400px mobile header (the naive anchor
             * would still fit, right at the edge) clamps down so the panel
             * never touches the viewport's own edge. Always opens downward
             * (top: rect.bottom + gap) — unlike team-switcher's menu, this
             * sits in a header, never short on room below it.
             */
            position() {
                const trigger = this.$refs.trigger;
                const menu = this.$refs.menu;
                const rect = trigger.getBoundingClientRect();
                const gap = 8;
                const width = menu.offsetWidth;
                const left = Math.min(Math.max(gap, rect.right - width), window.innerWidth - width - gap);
                const top = rect.bottom + gap;
                this.style = `top:${top}px; left:${left}px;`;
            },

            init() {
                // Keep the menu pinned to the trigger if the page moves
                // underneath it — same reasoning as team-switcher's own
                // init(), necessary here too since app-mobile-header scrolls
                // with the page rather than staying fixed. Kept on `this`
                // (not a local const) so destroy() below can remove the
                // exact same function reference — removeEventListener is a
                // no-op otherwise.
                this._reposition = () => this.open && this.position();
                window.addEventListener('scroll', this._reposition, true);
                window.addEventListener('resize', this._reposition);
            },

            // team-switcher's own position() listeners have never needed
            // this: that menu's x-data root sits inside Turbo's own swapped
            // content, so Alpine tears it down (and these listeners with it)
            // on every visit for free. This component's root is
            // data-turbo-permanent and never leaves the DOM that way, so
            // nothing calls this automatically today — kept anyway, as the
            // correct counterpart to init() for whatever stops being
            // permanent, or gets removed some other way, later.
            destroy() {
                window.removeEventListener('scroll', this._reposition, true);
                window.removeEventListener('resize', this._reposition);
            },
        };
    }
</script>
@endpush
@endonce
