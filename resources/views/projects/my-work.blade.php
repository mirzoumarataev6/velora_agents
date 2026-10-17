@extends('layouts.dashboard')

@section('title', __('projects.my_work').' · Velora')

@section('content')
<div class="mx-auto max-w-5xl space-y-7">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <a href="{{ route('projects.index') }}" class="inline-flex items-center gap-1 text-sm font-medium text-brand hover:underline"><x-icon name="arrow-left" size="h-3.5 w-3.5" />{{ __('projects.back_boards') }}</a>
            <h1 class="mt-3 page-title">{{ __('projects.my_work') }}</h1>
            <p class="page-sub">{{ __('projects.subtitle') }}</p>
        </div>
        <span class="badge badge-neutral">{{ $tasks->total() }}</span>
    </div>

    @if ($tasks->isEmpty())
        <div class="card card-pad py-16 text-center">
            <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-circle text-brand dark:bg-brand/15"><x-icon name="check" size="h-6 w-6" /></div>
            <p class="mt-4 font-display text-lg font-semibold">{{ __('projects.my_work_empty') }}</p>
        </div>
    @else
        <div class="space-y-3">
            @foreach ($tasks as $task)
                <a href="{{ route('projects.tasks.show', [$task->project_board_id, $task]) }}" class="card group flex flex-wrap items-center gap-4 p-4 transition-shadow hover:shadow-lg sm:p-5">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-circle text-brand dark:bg-brand/15" aria-hidden="true"><x-icon name="square" size="h-4 w-4" /></div>
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-medium group-hover:text-brand">{{ $task->title }}</p>
                        <p class="mt-1 text-xs text-muted dark:text-night-muted">{{ $task->projectBoard?->name }} · {{ $task->projectColumn?->name }}</p>
                    </div>
                    <div class="flex items-center gap-3 text-xs">
                        @if ($task->priority === 'urgent' || $task->priority === 'high')
                            <span class="badge {{ $task->priority === 'urgent' ? 'badge-danger' : 'badge-warning' }}">{{ __('projects.priority_'.$task->priority) }}</span>
                        @endif
                        <span class="{{ $task->due_at?->isPast() ? 'text-danger' : 'text-muted dark:text-night-muted' }}">{{ $task->due_at?->translatedFormat('d M') ?? __('projects.no_due') }}</span>
                    </div>
                </a>
            @endforeach
        </div>
        <div>{{ $tasks->links() }}</div>
    @endif
</div>
@endsection
