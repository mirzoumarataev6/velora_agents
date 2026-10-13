@php
    // Own query rather than threaded through every controller that renders
    // the dashboard shell (there is no View::composer for this today, and
    // adding one for a single partial would be more machinery than the
    // data warrants). One plain join, not Eloquent's `with(['team',
    // 'role'])` eager loading: the latter is 3 queries (memberships, then
    // a whereIn for teams, then a whereIn for roles) on EVERY dashboard
    // page, for data this partial only ever reads as plain name/slug
    // strings — a model hydration it never needed. This renders on every
    // authenticated page, so the difference is a flat +1 query per page
    // load versus +3; see Perf\QueryBudgetTest's own updated budgets.
    $__teamMemberships = \Illuminate\Support\Facades\DB::table('team_user')
        ->join('teams', 'teams.id', '=', 'team_user.team_id')
        ->join('roles', 'roles.id', '=', 'team_user.role_id')
        ->where('team_user.user_id', auth()->id())
        ->orderBy('team_user.joined_at')
        ->select('team_user.team_id', 'teams.name as team_name', 'roles.slug as role_slug')
        ->get();
    $__currentTeamId = auth()->user()->current_team_id;
    $__currentTeamName = $__teamMemberships->first(fn ($m) => (int) $m->team_id === (int) $__currentTeamId)?->team_name
        ?? $__teamMemberships->first()?->team_name
        ?? '—';
@endphp
{{--
    Same reasoning as dashboard-locale-switcher.blade.php's own docblock:
    the sidebar sets backdrop-blur-xl and a translate-x transition, both of
    which create a containing block AND a stacking context, so the menu is
    teleported to <body> with viewport-fixed coordinates rather than relying
    on z-index to escape the sidebar.
--}}
<div x-data="teamSwitcher()" class="shrink-0">
    <button type="button" x-ref="trigger" @click.stop="toggle()" @keydown.down.prevent="open ? focusItem(0) : toggle()"
        :aria-expanded="open ? 'true' : 'false'" aria-haspopup="menu"
        aria-label="{{ __('agents.nav.teams.switcher_label', ['name' => $__currentTeamName]) }}"
        class="flex w-full min-w-0 items-center gap-2 rounded-lg px-2 py-1.5 text-left transition-colors hover:bg-sunk dark:hover:bg-night-raised">
        <span class="icon-circle icon-circle-sm shrink-0 text-xs font-semibold" aria-hidden="true">
            {{ mb_strtoupper(mb_substr($__currentTeamName, 0, 1)) }}
        </span>
        <span class="min-w-0 flex-1 truncate text-sm font-medium">{{ $__currentTeamName }}</span>
        <svg class="h-4 w-4 shrink-0 text-muted dark:text-night-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M8 9l4-4 4 4M8 15l4 4 4-4"/>
        </svg>
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
            class="card fixed z-[110] w-64 overflow-hidden py-1">
            @foreach ($__teamMemberships as $loop_membership)
                <form method="POST" action="{{ route('teams.switch') }}">
                    @csrf
                    <input type="hidden" name="team_id" value="{{ $loop_membership->team_id }}" />
                    <button type="submit" role="menuitem" x-ref="item{{ $loop->index }}"
                        aria-current="{{ (int) $loop_membership->team_id === (int) $__currentTeamId ? 'true' : 'false' }}"
                        class="flex w-full items-center gap-2 px-4 py-2 text-left text-sm transition-colors hover:bg-paper focus:bg-paper focus:outline-none dark:hover:bg-white/5 dark:focus:bg-white/5 {{ (int) $loop_membership->team_id === (int) $__currentTeamId ? 'font-semibold text-brand' : 'text-ink dark:text-white/80' }}">
                        <span class="min-w-0 flex-1 truncate">{{ $loop_membership->team_name }}</span>
                        <span class="badge-neutral shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium">
                            {{ __('agents.nav.teams.roles.'.$loop_membership->role_slug) }}
                        </span>
                    </button>
                </form>
            @endforeach
        </div>
    </template>
</div>

@once
@push('scripts')
<script>
    function teamSwitcher() {
        return {
            open: false,
            style: '',
            index: 0,
            count: {{ $__teamMemberships->count() }},

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

            /** Same fixed-coordinate approach as the locale switcher — opens upward unless there isn't room above it. */
            position() {
                const menu = this.$refs.menu;
                const rect = this.$refs.trigger.getBoundingClientRect();
                const gap = 8;
                const height = menu.offsetHeight;
                const width = menu.offsetWidth;

                const openUp = rect.top - gap - height >= 0;
                const top = openUp ? rect.top - gap - height : Math.min(rect.bottom + gap, window.innerHeight - height - gap);
                const left = Math.min(Math.max(gap, rect.left), window.innerWidth - width - gap);

                this.style = `top:${Math.max(gap, top)}px; left:${left}px;`;
            },

            focusItem(i) {
                if (this.count === 0) return;
                this.index = (i + this.count) % this.count;
                this.$refs['item' + this.index]?.focus();
            },

            init() {
                const reposition = () => this.open && this.position();
                window.addEventListener('scroll', reposition, true);
                window.addEventListener('resize', reposition);
            },
        };
    }
</script>
@endpush
@endonce
