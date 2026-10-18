@extends('layouts.dashboard')

@section('title', $board->name.' · Velora')

@section('content')
@php
    $accent = in_array($board->accent, ['blue', 'violet', 'emerald', 'amber', 'rose'], true) ? $board->accent : 'blue';
    // PRJ-005: the search box, filter and each column's own page are all in the
    // query string. Opening or closing a card must not lose them, so every link
    // that only toggles the task panel goes through this instead of a bare
    // route() call — one place carrying the rest of the query string forward,
    // with `task` set (open) or left out (closed) on top of it.
    //
    // request()->query(), not ->except()/->all(): this view only ever renders on
    // a GET, so there is no request body to leak here today, but spelling it as
    // query() makes that boundary explicit rather than relying on nothing ever
    // POSTing this view. `board` is also stripped from the carried query and
    // re-added last — a stray ?board=99 in the URL must never outrank the real
    // route parameter and point a card link at another board.
    //
    // `settings` is stripped too and never re-added: it carries the board
    // settings panel's open state (PRJ-004), and every link built here opens a
    // card or closes the task panel — none of them should also reopen settings
    // behind it. The settings panel manages its own query parameter directly
    // via history.replaceState, not through this helper.
    $boardLink = fn (array $extra = []) => route('projects.boards.show', array_merge(
        \Illuminate\Support\Arr::except(request()->query(), ['board', 'task', 'settings']),
        ['board' => $board],
        $extra
    ));
@endphp
{{--
    data-scroll-key identifies this board for app.js's scroll-preserve listener:
    opening/closing a card is a Turbo visit, which scrolls a normal visit to
    the top like any link click, resetting the kanban strip's horizontal
    scroll (data-kanban-strip below) and the page's vertical scroll. Only
    links carrying data-preserve-scroll (the card overlay here, and the X/
    backdrop in task-panel.blade.php) opt into saving and restoring it — a
    link to a DIFFERENT board, or any link without the attribute (pagination,
    filters), must still land at the top as a normal visit would.
--}}
<div class="project-accent-{{ $accent }} min-w-0 space-y-5" x-data="projectKanban({ settingsOpen: @js($settingsOpen) })" data-scroll-key="board-{{ $board->id }}">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <a href="{{ route('projects.index') }}" class="inline-flex items-center gap-1 text-sm font-medium text-brand hover:underline"><x-icon name="arrow-left" size="h-3.5 w-3.5" />{{ __('projects.back_boards') }}</a>
            <div class="mt-3 flex items-center gap-3">
                <div class="project-board-icon flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl font-semibold">{{ mb_strtoupper(mb_substr($board->name, 0, 1)) }}</div>
                <div class="min-w-0"><h1 class="truncate font-display text-2xl font-semibold sm:text-3xl">{{ $board->name }}</h1><p class="text-sm text-muted dark:text-night-muted">{{ $board->description ?: ($canEdit ? ($dragEnabled ? __('projects.drag_hint') : __('projects.move_hint')) : __('projects.subtitle')) }}</p></div>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @if ($board->visibility === 'private')
                <span class="badge badge-neutral inline-flex items-center gap-1">
                    {{-- Not one of the shared icon component's marks (that set is calendar/
                    checklist/close/arrows/plus/check/square) — this is the same lock shape
                    already used for "private" on the boards index card, kept inline there too
                    rather than growing the shared set for a single one-off badge. --}}
                    <svg class="h-3.5 w-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 018 0v3"/></svg>
                    {{ __('projects.visibility_private') }}
                </span>
            @endif
            <a href="{{ route('projects.my') }}" class="btn btn-ghost btn-sm">{{ __('projects.my_work') }}</a>
            @if ($canManage)<a href="#board-settings" @click.prevent="openSettings()" class="btn btn-secondary btn-sm">{{ __('projects.board_settings') }}</a>@endif
        </div>
    </div>

    @if ($errors->any())
        <div role="alert" class="rounded-2xl border border-danger/20 bg-danger/5 px-5 py-4 text-sm text-danger dark:text-danger-light">
            <ul class="list-inside list-disc space-y-1">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <form method="GET" action="{{ route('projects.boards.show', $board) }}" class="card flex flex-wrap items-center gap-3 p-3 sm:p-4">
        <div class="relative min-w-48 flex-1">
            <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/></svg>
            <input name="q" value="{{ $search }}" placeholder="{{ __('projects.search') }}" aria-label="{{ __('projects.search') }}" class="field w-full py-2 pl-10 pr-3 text-sm" />
        </div>
        <select name="filter" aria-label="{{ __('projects.filter') }}" class="field px-3 py-2 text-sm">
            @foreach (['all', 'mine', 'overdue', 'soon', 'archived'] as $option)<option value="{{ $option }}" @selected($filter === $option)>{{ __('projects.filter_'.$option) }}</option>@endforeach
        </select>
        <button type="submit" class="btn btn-primary btn-sm">{{ __('projects.filter') }}</button>
        @if ($filterActive)<a href="{{ route('projects.boards.show', $board) }}" class="btn btn-ghost btn-sm">{{ __('projects.clear_filters') }}</a>@endif
    </form>

    <div class="project-kanban-strip flex snap-x gap-4 overflow-x-auto pb-6" aria-label="{{ $board->name }}" data-kanban-strip>
        @foreach ($columns as $column)
            <section data-project-column="{{ $column->id }}" @dragover.prevent="over($event)" @drop.prevent="drop($event, {{ $column->id }})"
                class="project-kanban-column flex w-[min(85vw,20rem)] shrink-0 snap-start flex-col rounded-2xl bg-sunk/80 p-3 dark:bg-night-raised/80 sm:w-80">
                <div class="flex items-center gap-2 px-1 pb-3">
                    <span class="h-2.5 w-2.5 rounded-full {{ $column->is_done ? 'bg-success' : 'project-dot' }}" aria-hidden="true"></span>
                    <h2 class="min-w-0 flex-1 truncate text-sm font-bold">{{ $column->name }}</h2>
                    {{-- PHASE 2 item 14: data-column-total/data-wip-limit are
                    the source of truth drop() below renders FROM — never the
                    DOM text content directly (overwriting it lost the " /
                    limit" suffix, since Blade's @if here emits a plain text
                    run with no element boundary to target separately) and
                    never a querySelectorAll() count of loaded cards (a
                    paginated column only has its FIRST page in the DOM, so
                    that undercounts the true total the moment a column
                    passes 50 cards). --}}
                    <span data-column-count data-column-total="{{ $pages[$column->id]->total() }}" data-wip-limit="{{ $column->wip_limit ?? '' }}" class="rounded-full bg-surface px-2 py-0.5 text-xs font-semibold text-muted dark:bg-night-card dark:text-night-muted">{{ $pages[$column->id]->total() }}@if ($column->wip_limit) / {{ $column->wip_limit }}@endif</span>
                </div>
                <div data-project-cards data-has-more-pages="{{ $pages[$column->id]->hasMorePages() ? '1' : '0' }}" class="min-h-20 flex-1 space-y-2">
                    @forelse ($column->tasks as $task)
                        <article data-project-task-id="{{ $task->id }}" data-move-url="{{ route('projects.tasks.move', [$board, $task]) }}"
                            @if ($canEdit && $dragEnabled) draggable="true" @dragstart="start($event, {{ $task->id }})" @dragend="dragId = null" @endif
                            class="project-task-card group card relative cursor-pointer p-4 transition-[transform,box-shadow] duration-150 hover:-translate-y-0.5 hover:shadow-lg">
                            <a href="{{ $boardLink(['task' => $task->id]) }}" data-preserve-scroll class="absolute inset-0 rounded-xl focus-visible:outline-2 focus-visible:outline-brand" aria-label="{{ $task->title }}"></a>
                            @if ($task->projectLabels->isNotEmpty())
                                <div class="relative pointer-events-none mb-2 flex flex-wrap gap-1">
                                    @foreach ($task->projectLabels as $label)
                                        <span class="project-label project-accent-{{ in_array($label->color, ['blue','violet','emerald','amber','rose'], true) ? $label->color : 'blue' }}">{{ $label->name }}</span>
                                    @endforeach
                                </div>
                            @endif
                            <h3 class="relative pointer-events-none break-words text-sm font-semibold leading-5">{{ $task->title }}</h3>
                            <div class="relative pointer-events-none mt-3 flex flex-wrap items-center gap-2 text-[11px]">
                            @if ($task->priority === 'urgent' || $task->priority === 'high')
                                <span class="badge {{ $task->priority === 'urgent' ? 'badge-danger' : 'badge-warning' }}">{{ __('projects.priority_'.$task->priority) }}</span>
                            @endif
                            @if ($task->archived_at)<span class="badge badge-neutral">{{ __('projects.archive') }}</span>@endif
                                @if ($task->due_at)
                                    <span class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 {{ ! $task->done_at && $task->due_at->isPast() ? 'bg-danger/10 font-semibold text-danger dark:text-danger-light' : 'bg-sunk text-muted dark:bg-night-raised dark:text-night-muted' }}"><x-icon name="calendar" size="h-3 w-3" />{{ $task->due_at->translatedFormat('d M') }}</span>
                                @endif
                                @if ($task->checklistItems->isNotEmpty())<span class="inline-flex items-center gap-1 text-muted dark:text-night-muted"><x-icon name="checklist" size="h-3 w-3" />{{ $task->checklistItems->whereNotNull('completed_at')->count() }}/{{ $task->checklistItems->count() }}</span>@endif
                                @if ($task->assignees->isNotEmpty())
                                    <span class="ml-auto flex -space-x-1.5">@foreach ($task->assignees->take(3) as $assignee)<span class="flex h-6 w-6 items-center justify-center rounded-full border-2 border-surface bg-brand-circle text-[9px] font-bold text-brand dark:border-night-card" title="{{ $assignee->name }}">{{ $assignee->initials() }}</span>@endforeach</span>
                                @endif
                            </div>
                        </article>
                    @empty
                        <p class="py-6 text-center text-xs text-muted dark:text-night-muted">{{ __('projects.no_cards') }}</p>
                    @endforelse
                </div>
                @if ($pages[$column->id]->hasPages())
                    <nav class="mt-3 flex items-center justify-between gap-2 border-t border-hairline pt-3 text-xs dark:border-white/10" aria-label="{{ $column->name }}">
                        @if ($pages[$column->id]->previousPageUrl())<a class="inline-flex items-center gap-1 font-medium text-brand hover:underline" href="{{ $pages[$column->id]->previousPageUrl() }}"><x-icon name="arrow-left" size="h-3 w-3" />{{ __('projects.previous') }}</a>@else<span></span>@endif
                        <span class="text-muted dark:text-night-muted">{{ __('projects.page_of', ['page' => $pages[$column->id]->currentPage(), 'total' => $pages[$column->id]->lastPage()]) }}</span>
                        @if ($pages[$column->id]->nextPageUrl())<a class="inline-flex items-center gap-1 font-medium text-brand hover:underline" href="{{ $pages[$column->id]->nextPageUrl() }}">{{ __('projects.next') }}<x-icon name="arrow-right" size="h-3 w-3" /></a>@else<span></span>@endif
                    </nav>
                @endif
                @if ($canCreate && ! $board->archived_at)
                    <div class="mt-3 border-t border-hairline pt-3 dark:border-white/10" x-data="{ addOpen: false }">
                        {{-- Deliberately no x-cloak on either state below: unlike the
                        settings/create-board panels on this page (JS-only features), this
                        form must still work with JS disabled, so the raw HTML renders it
                        already expanded. Alpine only enhances it down to the quiet toggle
                        once it loads — a brief flash of both on first paint, not a missing
                        feature, is the accepted trade-off. --}}
                        <button type="button" x-show="! addOpen" @click="addOpen = true; $nextTick(() => $refs.addCardTitle.focus())"
                            class="btn btn-ghost btn-sm w-full text-muted dark:text-night-muted">
                            <x-icon name="plus" size="h-4 w-4" />{{ __('projects.add_task') }}
                        </button>
                        <form x-show="addOpen" method="POST" action="{{ route('projects.tasks.store', $board) }}" class="space-y-2"
                            @keydown.escape="addOpen = false; $refs.addCardTitle.value = ''">
                            @csrf
                            <input type="hidden" name="column_id" value="{{ $column->id }}" />
                            <input x-ref="addCardTitle" name="title" required maxlength="255" placeholder="{{ __('projects.add_task') }}" aria-label="{{ __('projects.task_title') }}" class="field w-full px-3 py-2 text-sm" />
                            <div class="flex gap-2">
                                <button class="btn btn-primary btn-sm flex-1" type="submit">{{ __('projects.add') }}</button>
                                <button type="button" class="btn btn-ghost btn-sm" @click="addOpen = false; $refs.addCardTitle.value = ''" aria-label="{{ __('projects.cancel') }}"><x-icon name="close" size="h-4 w-4" /></button>
                            </div>
                        </form>
                    </div>
                @endif
            </section>
        @endforeach
        @if ($canManage && ! $board->archived_at)
            <section class="w-[min(85vw,18rem)] shrink-0 snap-start rounded-2xl border-2 border-dashed border-hairline p-4 dark:border-white/15 sm:w-72">
                <h2 class="flex items-center gap-1.5 text-sm font-semibold"><x-icon name="plus" size="h-4 w-4" />{{ __('projects.add_column') }}</h2>
                <form method="POST" action="{{ route('projects.columns.store', $board) }}" class="mt-4 space-y-3">
                    @csrf
                    <input name="name" required maxlength="255" placeholder="{{ __('projects.column_name') }}" class="field w-full px-3 py-2 text-sm" />
                    <label class="flex items-center gap-2 text-xs text-muted dark:text-night-muted"><input type="checkbox" name="is_done" value="1" />{{ __('projects.done_column') }}</label>
                    <button type="submit" class="btn btn-secondary btn-sm w-full">{{ __('projects.add_column') }}</button>
                </form>
            </section>
        @endif
    </div>

    @if ($archivedCount > 0 && $filter !== 'archived')<a href="{{ route('projects.boards.show', [$board, 'filter' => 'archived']) }}" class="inline-flex items-center gap-1 text-sm font-medium text-brand hover:underline">{{ __('projects.archived_tasks') }} ({{ $archivedCount }})<x-icon name="arrow-right" size="h-3.5 w-3.5" /></a>@endif

    @if ($canManage)
        @include('projects.partials.board-settings')
    @endif
</div>

@if ($selectedTask)
    @include('projects.partials.task-panel')
@endif

@push('scripts')
<script>
    /**
     * PRJ-004: the panel's open state has to survive a plain-POST settings
     * form redirecting back to this same page. back() builds that redirect
     * from the Referer header, and a URL fragment (#board-settings) is never
     * sent as part of a Referer — only the query string is — so a fragment
     * alone closed the panel after every single edit inside it. ?settings=1
     * rides in the query string instead, kept in sync with the address bar
     * via history.replaceState (no Turbo visit, so Turbo's own history.state
     * is passed through untouched) each time the panel opens or closes, so
     * the NEXT form submit's Referer carries it forward.
     */
    function setSettingsQueryParam(open) {
        const url = new URL(window.location.href);
        if (open) url.searchParams.set('settings', '1');
        else url.searchParams.delete('settings');
        history.replaceState(history.state, '', url);
    }

    function projectKanban(options = {}) {
        return {
            dragId: null,
            busy: false,
            // The server decides the initial value from ?settings=1 (and only
            // for a user who can manage the board — see BoardController), but
            // the #board-settings hash link some pages still use must keep
            // working too, so either source opens it.
            settingsOpen: Boolean(options.settingsOpen) || window.location.hash === '#board-settings',
            init() {
                // Runs on every Turbo render of this page, same as the rest of
                // this component. Only fires when the panel is ALREADY open on
                // load (a settings-form redirect, or the hash link) — the
                // click-driven open below does its own smooth scroll — and
                // jumps straight there so the user isn't left looking at the
                // top of the board with the panel they just edited off-screen.
                if (this.settingsOpen) {
                    this.$nextTick(() => document.getElementById('board-settings')?.scrollIntoView());
                }
            },
            openSettings() {
                this.settingsOpen = true;
                setSettingsQueryParam(true);
                this.$nextTick(() => document.getElementById('board-settings')?.scrollIntoView({ behavior: 'smooth' }));
            },
            closeSettings() {
                this.settingsOpen = false;
                setSettingsQueryParam(false);
            },
            start(event, id) {
                this.dragId = id;
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', String(id));
            },
            over(event) { event.dataTransfer.dropEffect = 'move'; },
            /** See the comment at its call site in drop() for why this exists. */
            renderCount(columnId, delta) {
                const count = document.querySelector(`[data-project-column="${columnId}"] [data-column-count]`);
                if (!count) return;
                const total = Math.max(0, parseInt(count.dataset.columnTotal, 10) + delta);
                count.dataset.columnTotal = String(total);
                const limit = count.dataset.wipLimit;
                count.textContent = limit ? `${total} / ${limit}` : String(total);
            },
            async drop(event, columnId) {
                if (!this.dragId || this.busy) return;
                const card = document.querySelector(`[data-project-task-id="${this.dragId}"]`);
                const lane = document.querySelector(`[data-project-column="${columnId}"] [data-project-cards]`);
                const before = event.target.closest('[data-project-task-id]');
                if (!card || !lane || before === card) return;
                const beforeId = before?.parentElement === lane ? before.dataset.projectTaskId : null;
                // PHASE 2 item 12: dropping onto a specific visible card is
                // exact no matter how many more cards this column has beyond
                // the current page — the server resolves position among the
                // column's REAL full sibling order, not just what's on
                // screen. Dropping into the lane's empty TAIL space (no
                // specific card under the cursor) is only as exact as "the
                // end of the column" — true when this is the column's last
                // page, wrong (silently jumps past every hidden card) when
                // it isn't. Rejected rather than guessed; the visible pages
                // this board already supports (the column's own pagination
                // links) are how to reach a column's true end to drop there.
                if (beforeId === null && lane.dataset.hasMorePages === '1') {
                    window.showToast?.(@js(__('projects.errors.drop_needs_target')), 'error');
                    return;
                }
                const previousParent = card.parentElement;
                const previousNext = card.nextElementSibling;
                const previousColumnId = previousParent.closest('[data-project-column]')?.dataset.projectColumn;
                if (before && before.parentElement === lane) lane.insertBefore(card, before);
                else lane.append(card);
                this.busy = true;
                try {
                    const response = await fetch(card.dataset.moveUrl, {
                        method: 'PATCH', credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                        body: JSON.stringify({ column_id: columnId, before_task_id: beforeId }),
                    });
                    if (!response.ok) throw new Error((await response.json().catch(() => ({}))).message || 'move failed');
                    // PHASE 2 item 14: the count span renders from its own
                    // data-column-total/data-wip-limit, bumped by ±1 here —
                    // never from a fresh DOM query. A column only has its
                    // first page of cards in the DOM (see item 12 above), so
                    // querySelectorAll('[data-project-task-id]').length
                    // silently undercounts a paginated column after every
                    // single move; and overwriting the span's text wholesale
                    // (rather than re-deriving "n / limit" from these two
                    // attributes) is what dropped the " / limit" suffix
                    // entirely the moment a card moved.
                    this.renderCount(columnId, +1);
                    // String comparison deliberately: columnId arrives as a
                    // number (interpolated from Blade), previousColumnId
                    // always as a string (dataset is DOMStringMap) — a
                    // same-column reorder must not touch the count at all.
                    if (previousColumnId && String(previousColumnId) !== String(columnId)) this.renderCount(previousColumnId, -1);
                    window.showToast?.(@js(__('projects.messages.task_moved')), 'success');
                } catch (error) {
                    previousParent.insertBefore(card, previousNext);
                    window.showToast?.(error.message, 'error');
                } finally { this.busy = false; this.dragId = null; }
            },
        };
    }
</script>
@endpush
@endsection
