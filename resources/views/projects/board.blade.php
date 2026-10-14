@extends('layouts.dashboard')

@section('title', $board->name.' · Velora')

@section('content')
@php $accent = in_array($board->accent, ['blue', 'violet', 'emerald', 'amber', 'rose'], true) ? $board->accent : 'blue'; @endphp
<div class="project-accent-{{ $accent }} min-w-0 space-y-5" x-data="projectKanban()">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <a href="{{ route('projects.index') }}" class="text-sm font-medium text-brand hover:underline">← {{ __('projects.back_boards') }}</a>
            <div class="mt-3 flex items-center gap-3">
                <div class="project-board-icon flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl font-semibold">{{ mb_strtoupper(mb_substr($board->name, 0, 1)) }}</div>
                <div class="min-w-0"><h1 class="truncate font-display text-2xl font-semibold sm:text-3xl">{{ $board->name }}</h1><p class="text-sm text-muted dark:text-night-muted">{{ $board->description ?: ($canEdit ? ($dragEnabled ? __('projects.drag_hint') : __('projects.move_hint')) : __('projects.subtitle')) }}</p></div>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @if ($board->visibility === 'private')<span class="badge badge-neutral">◈ {{ __('projects.visibility_private') }}</span>@endif
            <a href="{{ route('projects.my') }}" class="btn btn-ghost btn-sm">{{ __('projects.my_work') }}</a>
            @if ($canManage)<a href="#board-settings" @click.prevent="settingsOpen = true; $nextTick(() => document.getElementById('board-settings').scrollIntoView({ behavior: 'smooth' }))" class="btn btn-secondary btn-sm">{{ __('projects.board_settings') }}</a>@endif
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

    <div class="project-kanban-strip flex snap-x gap-4 overflow-x-auto pb-6" aria-label="{{ $board->name }}">
        @foreach ($columns as $column)
            <section data-project-column="{{ $column->id }}" @dragover.prevent="over($event)" @drop.prevent="drop($event, {{ $column->id }})"
                class="project-kanban-column flex w-[min(85vw,20rem)] shrink-0 snap-start flex-col rounded-2xl bg-sunk/80 p-3 dark:bg-night-raised/80 sm:w-80">
                <div class="flex items-center gap-2 px-1 pb-3">
                    <span class="h-2.5 w-2.5 rounded-full {{ $column->is_done ? 'bg-success' : 'project-dot' }}" aria-hidden="true"></span>
                    <h2 class="min-w-0 flex-1 truncate text-sm font-bold">{{ $column->name }}</h2>
                    <span data-column-count class="rounded-full bg-surface px-2 py-0.5 text-xs font-semibold text-muted dark:bg-night-card dark:text-night-muted">{{ $pages[$column->id]->total() }}@if ($column->wip_limit) / {{ $column->wip_limit }}@endif</span>
                </div>
                <div data-project-cards class="min-h-20 flex-1 space-y-2">
                    @forelse ($column->tasks as $task)
                        <article data-project-task-id="{{ $task->id }}" data-move-url="{{ route('projects.tasks.move', [$board, $task]) }}"
                            @if ($canEdit && $dragEnabled) draggable="true" @dragstart="start($event, {{ $task->id }})" @dragend="dragId = null" @endif
                            class="project-task-card group card relative cursor-pointer p-4 transition-[transform,box-shadow] duration-150 hover:-translate-y-0.5 hover:shadow-lg">
                            <a href="{{ route('projects.boards.show', [$board, 'task' => $task->id]) }}" class="absolute inset-0 rounded-xl focus-visible:outline-2 focus-visible:outline-brand" aria-label="{{ $task->title }}"></a>
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
                                    <span class="rounded-md px-1.5 py-0.5 {{ ! $task->done_at && $task->due_at->isPast() ? 'bg-danger/10 font-semibold text-danger dark:text-danger-light' : 'bg-sunk text-muted dark:bg-night-raised dark:text-night-muted' }}">◷ {{ $task->due_at->translatedFormat('d M') }}</span>
                                @endif
                                @if ($task->checklistItems->isNotEmpty())<span class="text-muted dark:text-night-muted">☑ {{ $task->checklistItems->whereNotNull('completed_at')->count() }}/{{ $task->checklistItems->count() }}</span>@endif
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
                        @if ($pages[$column->id]->previousPageUrl())<a class="font-medium text-brand hover:underline" href="{{ $pages[$column->id]->previousPageUrl() }}">← {{ __('projects.previous') }}</a>@else<span></span>@endif
                        <span class="text-muted dark:text-night-muted">{{ __('projects.page_of', ['page' => $pages[$column->id]->currentPage(), 'total' => $pages[$column->id]->lastPage()]) }}</span>
                        @if ($pages[$column->id]->nextPageUrl())<a class="font-medium text-brand hover:underline" href="{{ $pages[$column->id]->nextPageUrl() }}">{{ __('projects.next') }} →</a>@else<span></span>@endif
                    </nav>
                @endif
                @if ($canCreate && ! $board->archived_at)
                    <form method="POST" action="{{ route('projects.tasks.store', $board) }}" class="mt-3 space-y-2 border-t border-hairline pt-3 dark:border-white/10">
                        @csrf
                        <input type="hidden" name="column_id" value="{{ $column->id }}" />
                        <input name="title" required maxlength="255" placeholder="+ {{ __('projects.add_task') }}" aria-label="{{ __('projects.task_title') }}" class="field w-full px-3 py-2 text-sm" />
                        <button class="btn btn-primary btn-sm w-full" type="submit">{{ __('projects.add_task') }}</button>
                    </form>
                @endif
            </section>
        @endforeach
        @if ($canManage && ! $board->archived_at)
            <section class="w-[min(85vw,18rem)] shrink-0 snap-start rounded-2xl border-2 border-dashed border-hairline p-4 dark:border-white/15 sm:w-72">
                <h2 class="text-sm font-semibold">+ {{ __('projects.add_column') }}</h2>
                <form method="POST" action="{{ route('projects.columns.store', $board) }}" class="mt-4 space-y-3">
                    @csrf
                    <input name="name" required maxlength="255" placeholder="{{ __('projects.column_name') }}" class="field w-full px-3 py-2 text-sm" />
                    <label class="flex items-center gap-2 text-xs text-muted dark:text-night-muted"><input type="checkbox" name="is_done" value="1" />{{ __('projects.done_column') }}</label>
                    <button type="submit" class="btn btn-secondary btn-sm w-full">{{ __('projects.add_column') }}</button>
                </form>
            </section>
        @endif
    </div>

    @if ($archivedCount > 0 && $filter !== 'archived')<a href="{{ route('projects.boards.show', [$board, 'filter' => 'archived']) }}" class="text-sm font-medium text-brand hover:underline">{{ __('projects.archived_tasks') }} ({{ $archivedCount }}) →</a>@endif

    @if ($canManage)
        @include('projects.partials.board-settings')
    @endif
</div>

@if ($selectedTask)
    @include('projects.partials.task-panel')
@endif

@push('scripts')
<script>
    function projectKanban() {
        return {
            dragId: null,
            busy: false,
            settingsOpen: window.location.hash === '#board-settings',
            start(event, id) {
                this.dragId = id;
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', String(id));
            },
            over(event) { event.dataTransfer.dropEffect = 'move'; },
            async drop(event, columnId) {
                if (!this.dragId || this.busy) return;
                const card = document.querySelector(`[data-project-task-id="${this.dragId}"]`);
                const lane = document.querySelector(`[data-project-column="${columnId}"] [data-project-cards]`);
                const before = event.target.closest('[data-project-task-id]');
                if (!card || !lane || before === card) return;
                const beforeId = before?.parentElement === lane ? before.dataset.projectTaskId : null;
                const previousParent = card.parentElement;
                const previousNext = card.nextElementSibling;
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
                    document.querySelectorAll('[data-project-column]').forEach((column) => {
                        const count = column.querySelector('[data-column-count]');
                        if (count) count.firstChild.textContent = String(column.querySelectorAll('[data-project-task-id]').length);
                    });
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
