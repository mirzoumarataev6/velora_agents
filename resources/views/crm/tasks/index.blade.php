@extends('layouts.dashboard')

@section('title', __('crm.tasks.title'))

@section('content')
<div x-data="{ adding: @js($errors->hasAny(['title', 'due_at', 'customer_id', 'deal_id'])) }">
    <div class="page-header">
        <div>
            <h1 class="page-title">{{ __('crm.tasks.heading') }}</h1>
            <p class="page-sub">{{ __('crm.tasks.subheading') }}</p>
        </div>
        <div class="page-actions">
            <button type="button" @click="adding = !adding" class="btn btn-primary btn-sm">{{ __('crm.tasks.add') }}</button>
        </div>
    </div>

    @include('crm.partials.subnav')

    @if (session('status'))
        <div class="card card-pad-sm mt-4 border-brand/30 bg-brand/5 text-sm">{{ session('status') }}</div>
    @endif

    {{-- Inline rather than a modal: adding a reminder should cost one click and
         no context switch, or it does not get added. --}}
    <form method="POST" action="{{ route('crm.tasks.store') }}" x-show="adding" x-cloak
        class="card card-pad mt-4 grid gap-3 sm:grid-cols-[2fr_1fr_1fr_auto]">
        @csrf
        <div>
            <label for="task-title" class="mb-1 block text-sm font-medium">{{ __('crm.tasks.add_title') }}</label>
            <input id="task-title" name="title" required maxlength="255" class="field w-full px-3 py-2 text-sm" />
        </div>
        <div>
            <label for="task-due" class="mb-1 block text-sm font-medium">{{ __('crm.tasks.add_due') }}</label>
            <input id="task-due" name="due_at" type="datetime-local" class="field w-full px-3 py-2 text-sm" />
        </div>
        <div>
            <label for="task-customer" class="mb-1 block text-sm font-medium">{{ __('crm.tasks.add_customer') }}</label>
            <select id="task-customer" name="customer_id" class="field w-full px-3 py-2 text-sm">
                <option value="">{{ __('crm.tasks.no_customer') }}</option>
                @foreach ($customers as $customer)
                    <option value="{{ $customer->id }}" @selected((string) old('customer_id') === (string) $customer->id)>{{ $customer->name ?: __('crm.customers.no_name') }}</option>
                @endforeach
            </select>
            @error('customer_id') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
        </div>
        <div class="flex items-end">
            <button type="submit" class="btn btn-primary btn-sm btn-block">{{ __('crm.tasks.add_submit') }}</button>
        </div>
    </form>

    <div class="mt-4 flex flex-wrap items-center gap-2">
        @foreach (['open', 'today', 'overdue', 'done'] as $filter)
            <a href="{{ route('crm.tasks.index', ['filter' => $filter]) }}"
                class="rounded-full border px-4 py-1.5 text-sm font-medium transition-colors {{ $activeFilter === $filter ? 'border-brand bg-brand/10 text-brand' : 'border-black/10 text-muted hover:bg-black/5 dark:border-white/10 dark:text-night-muted dark:hover:bg-white/5' }}">
                {{ __('crm.tasks.filters.'.$filter) }}
                @if ($filter === 'overdue' && $counts['overdue'] > 0)
                    <span class="badge badge-danger tnum">{{ $counts['overdue'] }}</span>
                @endif
            </a>
        @endforeach
    </div>

    @if ($tasks->isEmpty())
        <div class="empty-state mt-6">
            <div class="empty-state-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg>
            </div>
            <p class="empty-state-title">{{ __('crm.tasks.empty_title') }}</p>
            <p class="empty-state-text">{{ __('crm.tasks.empty_description') }}</p>
        </div>
    @else
        <div class="card mt-5 divide-y divide-black/5 dark:divide-white/5">
            @foreach ($tasks as $task)
                <div class="flex items-center gap-3 px-4 py-3">
                    {{-- The tick is its own one-field form, so finishing a task
                         is one click and never opens an editor. --}}
                    <form method="POST" action="{{ route('crm.tasks.update', $task) }}" class="flex shrink-0">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="done" value="{{ $task->isDone() ? 0 : 1 }}" />
                        <button type="submit"
                            aria-label="{{ __('crm.tasks.done_label') }}"
                            class="flex h-5 w-5 items-center justify-center rounded-md border transition-colors {{ $task->isDone() ? 'border-brand bg-brand text-white' : 'border-black/20 hover:border-brand dark:border-white/20' }}">
                            @if ($task->isDone())
                                <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                            @endif
                        </button>
                    </form>

                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm {{ $task->isDone() ? 'text-muted line-through dark:text-night-muted' : '' }}">{{ $task->title }}</p>
                        <p class="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-muted dark:text-night-muted">
                            <span class="{{ $task->isOverdue() ? 'font-medium text-danger' : '' }}">
                                {{ $task->due_at?->translatedFormat('d MMM, HH:mm') ?? __('crm.tasks.no_due') }}
                            </span>
                            @if ($task->customer)
                                <a href="{{ route('crm.customers.show', $task->customer) }}" class="hover:underline">
                                    {{ $task->customer->name ?: __('crm.customers.no_name') }}
                                </a>
                            @endif
                            @if ($task->deal)
                                <a href="{{ route('crm.board', ['q' => $task->deal->title]) }}" class="hover:underline">
                                    {{ __('crm.tasks.for_deal') }}: {{ $task->deal->title }}
                                </a>
                            @endif
                            @if ($task->isOverdue())
                                <span class="badge badge-danger">{{ __('crm.tasks.overdue') }}</span>
                            @endif
                        </p>
                    </div>

                    <form method="POST" action="{{ route('crm.tasks.destroy', $task) }}"
                        onsubmit="return confirm(@js(__('crm.tasks.delete_confirm')))" class="shrink-0">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-ghost btn-sm" aria-label="{{ __('crm.tasks.delete') }}">✕</button>
                    </form>
                </div>
            @endforeach
        </div>

        <div class="mt-6">{{ $tasks->links() }}</div>
    @endif
</div>
@endsection
