@extends('layouts.dashboard')

@section('title', __('projects.title'))

@section('content')
<div class="mx-auto max-w-7xl space-y-8" x-data="{ createOpen: false }">
    @if ($errors->any())<div role="alert" class="rounded-xl bg-danger/10 p-4 text-sm text-danger">{{ $errors->first() }}</div>@endif
    <section class="relative overflow-hidden rounded-3xl bg-ink px-6 py-8 text-white shadow-xl sm:px-9 sm:py-10 dark:bg-night-card">
        <div class="pointer-events-none absolute -right-16 -top-28 h-80 w-80 rounded-full bg-brand/30 blur-3xl" aria-hidden="true"></div>
        <div class="pointer-events-none absolute -bottom-28 right-1/3 h-56 w-56 rounded-full bg-series-3/20 blur-3xl" aria-hidden="true"></div>
        <div class="relative flex flex-wrap items-end justify-between gap-6">
            <div class="max-w-2xl">
                <p class="mb-3 text-xs font-bold uppercase tracking-[.22em] text-brand-light">VELORA / WORK</p>
                <h1 class="font-display text-3xl font-semibold tracking-tight sm:text-4xl">{{ __('projects.heading') }}</h1>
                <p class="mt-3 text-sm leading-6 text-white/70 sm:text-base">{{ __('projects.subtitle') }}</p>
            </div>
            <div class="flex flex-wrap gap-3">
                <a href="{{ route('projects.my') }}" class="btn bg-white/10 text-white ring-1 ring-white/20 hover:bg-white/20">{{ __('projects.my_work') }}</a>
                @can('projects.manage')
                    <button type="button" @click="createOpen = true" class="btn bg-white text-ink hover:bg-white/90">+ {{ __('projects.new_board') }}</button>
                @endcan
            </div>
        </div>
    </section>

    <div class="flex items-end justify-between gap-4">
        <div>
            <p class="text-xs font-bold uppercase tracking-[.18em] text-muted dark:text-night-muted">{{ __('projects.boards') }}</p>
            <h2 class="mt-1 font-display text-2xl font-semibold">{{ __('projects.boards') }}</h2>
        </div>
        <span class="badge badge-neutral">{{ $boards->count() }}</span>
    </div>

    @if ($boards->isEmpty())
        <div class="card card-pad py-16 text-center">
            <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-brand-circle text-brand dark:bg-brand/15">
                <svg class="h-8 w-8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M9 4v16M13 9h5M13 13h5"/></svg>
            </div>
            <h3 class="mt-5 font-display text-xl font-semibold">{{ __('projects.empty_boards_title') }}</h3>
            <p class="mx-auto mt-2 max-w-lg text-sm text-muted dark:text-night-muted">{{ __('projects.empty_boards_text') }}</p>
            @can('projects.manage')
                <button type="button" @click="createOpen = true" class="btn btn-primary mt-6">{{ __('projects.create_board') }}</button>
            @endcan
        </div>
    @else
        <div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($boards as $board)
                @php $accent = in_array($board->accent, ['blue', 'violet', 'emerald', 'amber', 'rose'], true) ? $board->accent : 'blue'; @endphp
                <a href="{{ route('projects.boards.show', $board) }}" class="project-board-card project-accent-{{ $accent }} group card relative flex min-h-56 flex-col overflow-hidden p-6 transition-all duration-200 hover:-translate-y-1 hover:shadow-xl focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand">
                    <div class="project-board-card-glow pointer-events-none absolute inset-y-0 right-0 w-28 opacity-50 transition-opacity group-hover:opacity-80" aria-hidden="true"></div>
                    <div class="relative flex items-start justify-between gap-3">
                        <div class="project-board-icon flex h-11 w-11 items-center justify-center rounded-2xl text-lg font-semibold">{{ mb_strtoupper(mb_substr($board->name, 0, 1)) }}</div>
                        @if ($board->visibility === 'private')
                            <svg class="h-4 w-4 text-muted dark:text-night-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-label="{{ __('projects.visibility_private') }}"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 018 0v3"/></svg>
                        @endif
                    </div>
                    <h3 class="relative mt-5 font-display text-lg font-semibold">{{ $board->name }}</h3>
                    <p class="relative mt-1 line-clamp-2 min-h-10 text-sm text-muted dark:text-night-muted">{{ $board->description }}</p>
                    <div class="relative mt-auto flex items-center justify-between border-t border-hairline pt-4 text-xs dark:border-white/10">
                        <span class="text-muted dark:text-night-muted">{{ __('projects.open_tasks') }} <strong class="ml-1 text-ink dark:text-night-ink">{{ $board->open_tasks_count }}</strong></span>
                        <span class="font-semibold text-brand transition-transform group-hover:translate-x-1">{{ __('projects.open_board') }} →</span>
                    </div>
                </a>
            @endforeach
        </div>
    @endif

    @if ($archivedBoards->isNotEmpty())
        <section class="card card-pad space-y-3">
            <h2 class="font-display text-lg font-semibold">{{ __('projects.archived_boards') }}</h2>
            @foreach ($archivedBoards as $archivedBoard)
                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-hairline pt-3 dark:border-white/10">
                    <span class="text-sm font-medium">{{ $archivedBoard->name }}</span>
                    <form method="POST" action="{{ route('projects.boards.archive', $archivedBoard) }}">@csrf @method('PATCH')<button type="submit" class="btn btn-secondary btn-sm">{{ __('projects.restore_board') }}</button></form>
                </div>
            @endforeach
        </section>
    @endif

    @can('projects.manage')
        <div x-show="createOpen" x-cloak @keydown.escape.window="createOpen = false" class="fixed inset-0 z-[90] flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-ink/65 backdrop-blur-sm" @click="createOpen = false"></div>
            <div role="dialog" aria-modal="true" aria-labelledby="create-board-title" class="card relative w-full max-w-lg p-6 shadow-2xl sm:p-8">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[.18em] text-brand">VELORA / WORK</p>
                        <h2 id="create-board-title" class="mt-1 font-display text-2xl font-semibold">{{ __('projects.new_board') }}</h2>
                    </div>
                    <button type="button" @click="createOpen = false" class="btn btn-ghost btn-sm" aria-label="{{ __('projects.close') }}">✕</button>
                </div>
                <form method="POST" action="{{ route('projects.boards.store') }}" class="mt-6 space-y-4">
                    @csrf
                    <div><label for="board-name" class="mb-1.5 block text-sm font-medium">{{ __('projects.board_name') }}</label><input id="board-name" name="name" required maxlength="255" value="{{ old('name') }}" class="field w-full px-4 py-2.5" /></div>
                    <div><label for="board-description" class="mb-1.5 block text-sm font-medium">{{ __('projects.board_description') }}</label><textarea id="board-description" name="description" rows="3" maxlength="3000" class="field w-full px-4 py-2.5">{{ old('description') }}</textarea></div>
                    <div><label for="board-visibility" class="mb-1.5 block text-sm font-medium">{{ __('projects.visibility') }}</label><select id="board-visibility" name="visibility" class="field w-full px-4 py-2.5"><option value="team">{{ __('projects.visibility_team') }}</option><option value="private">{{ __('projects.visibility_private') }}</option></select></div>
                    <div><label for="board-accent" class="mb-1.5 block text-sm font-medium">{{ __('projects.accent') }}</label><select id="board-accent" name="accent" class="field w-full px-4 py-2.5">@foreach (['blue', 'violet', 'emerald', 'amber', 'rose'] as $color)<option value="{{ $color }}">{{ __('projects.color_'.$color) }}</option>@endforeach</select></div>
                    <div class="flex justify-end gap-2 pt-2"><button type="button" @click="createOpen = false" class="btn btn-ghost">{{ __('projects.cancel') }}</button><button type="submit" class="btn btn-primary">{{ __('projects.create_board') }}</button></div>
                </form>
            </div>
        </div>
    @endcan
</div>
@endsection
