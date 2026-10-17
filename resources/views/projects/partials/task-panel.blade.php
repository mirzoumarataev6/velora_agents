<div class="fixed inset-0 z-[80] flex justify-end" x-data @keydown.escape.window="window.location.href = @js(route('projects.boards.show', $board))">
    <a href="{{ route('projects.boards.show', $board) }}" class="absolute inset-0 bg-ink/60 backdrop-blur-[2px]" aria-label="{{ __('projects.close') }}"></a>
    <aside role="dialog" aria-modal="true" aria-labelledby="project-task-title" class="relative flex h-full w-full max-w-2xl flex-col overflow-y-auto bg-surface p-5 shadow-2xl dark:bg-night-card sm:p-8">
        <div class="flex items-start justify-between gap-4">
            <div class="min-w-0"><p class="text-xs font-bold uppercase tracking-[.16em] text-brand">{{ $board->name }} / {{ $selectedTask->projectColumn?->name }}</p><h2 id="project-task-title" class="mt-2 break-words font-display text-2xl font-semibold">{{ $selectedTask->title }}</h2></div>
            <a href="{{ route('projects.boards.show', $board) }}" class="btn btn-ghost btn-sm" aria-label="{{ __('projects.close') }}"><x-icon name="close" size="h-4 w-4" /></a>
        </div>

        @if ($canEdit && ! $selectedTask->archived_at)
            <form method="POST" action="{{ route('projects.tasks.update', [$board, $selectedTask]) }}" class="mt-6 space-y-5">
                @csrf @method('PATCH')
                <label class="block text-sm font-medium">{{ __('projects.task_title') }}<input name="title" value="{{ $selectedTask->title }}" required maxlength="255" class="field mt-1.5 w-full px-3 py-2" /></label>
                <label class="block text-sm font-medium">{{ __('projects.task_description') }}<textarea name="description" rows="5" maxlength="20000" class="field mt-1.5 w-full px-3 py-2">{{ $selectedTask->description }}</textarea></label>
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="text-sm font-medium">{{ __('projects.priority') }}<select name="priority" class="field mt-1.5 w-full px-3 py-2">@foreach (\App\Models\Task::PRIORITIES as $priority)<option value="{{ $priority }}" @selected($selectedTask->priority === $priority)>{{ __('projects.priority_'.$priority) }}</option>@endforeach</select></label>
                    <label class="text-sm font-medium">{{ __('projects.due_at') }}<input type="datetime-local" name="due_at" value="{{ $selectedTask->due_at?->format('Y-m-d\TH:i') }}" class="field mt-1.5 w-full px-3 py-2" /></label>
                </div>
                @if ($canAssign)
                    <fieldset><input type="hidden" name="assignees_present" value="1" /><legend class="mb-2 text-sm font-medium">{{ __('projects.assignees') }}</legend><div class="grid max-h-32 gap-2 overflow-y-auto rounded-xl border border-hairline p-3 dark:border-hairline sm:grid-cols-2">@foreach ($members as $membership) @if ($board->visibility === 'team' || $board->created_by === $membership->user_id || $board->members->contains('id', $membership->user_id))<label class="flex items-center gap-2 text-sm"><input type="checkbox" name="assignee_ids[]" value="{{ $membership->user_id }}" @checked($selectedTask->assignees->contains('id', $membership->user_id)) />{{ $membership->user?->name }}</label>@endif @endforeach</div></fieldset>
                @endif
                @if ($labels->isNotEmpty())<fieldset><input type="hidden" name="labels_present" value="1" /><legend class="mb-2 text-sm font-medium">{{ __('projects.labels') }}</legend><div class="flex flex-wrap gap-3">@foreach ($labels as $label)<label class="flex items-center gap-1.5 text-sm"><input type="checkbox" name="label_ids[]" value="{{ $label->id }}" @checked($selectedTask->projectLabels->contains('id', $label->id)) /><span class="project-label project-accent-{{ in_array($label->color, ['blue','violet','emerald','amber','rose'], true) ? $label->color : 'blue' }}">{{ $label->name }}</span></label>@endforeach</div></fieldset>@endif
                @if ($canViewCrm)
                    <fieldset class="grid gap-3 sm:grid-cols-2"><legend class="mb-2 text-sm font-medium">{{ __('projects.linked_crm') }}</legend>
                        <label class="text-xs">{{ __('projects.customer') }}<select name="customer_id" class="field mt-1 w-full px-2 py-2 text-sm"><option value="">{{ __('projects.none') }}</option>@foreach ($customers as $customer)<option value="{{ $customer->id }}" @selected($selectedTask->customer_id === $customer->id)>{{ $customer->name }}</option>@endforeach</select></label>
                        <label class="text-xs">{{ __('projects.deal') }}<select name="deal_id" class="field mt-1 w-full px-2 py-2 text-sm"><option value="">{{ __('projects.none') }}</option>@foreach ($deals as $deal)<option value="{{ $deal->id }}" @selected($selectedTask->deal_id === $deal->id)>{{ $deal->title }}</option>@endforeach</select></label>
                    </fieldset>
                @endif
                <button type="submit" class="btn btn-primary">{{ __('projects.save') }}</button>
            </form>
            <div class="mt-6 flex flex-wrap gap-2 border-t border-hairline pt-5 dark:border-white/10">
                <form method="POST" action="{{ route('projects.tasks.move', [$board, $selectedTask]) }}" class="flex flex-1 flex-wrap items-center gap-2">@csrf @method('PATCH')<label for="move-column" class="text-sm font-medium">{{ __('projects.move_to') }}</label><select id="move-column" name="column_id" class="field min-w-36 flex-1 px-3 py-2 text-sm">@foreach ($columns as $column)<option value="{{ $column->id }}" @selected($selectedTask->project_column_id === $column->id)>{{ $column->name }}</option>@endforeach</select><button type="submit" class="btn btn-secondary btn-sm">{{ __('projects.move') }}</button></form>
                <form method="POST" action="{{ route('projects.tasks.archive', [$board, $selectedTask]) }}">@csrf @method('PATCH')<button type="submit" class="btn btn-ghost btn-sm text-danger">{{ $selectedTask->archived_at ? __('projects.restore') : __('projects.archive') }}</button></form>
            </div>
        @else
            <div class="mt-6 space-y-4"><p class="whitespace-pre-wrap text-sm leading-6">{{ $selectedTask->description }}</p><p class="text-sm text-muted dark:text-night-muted">{{ __('projects.due_at') }}: {{ $selectedTask->due_at?->translatedFormat('d M Y H:i') ?? __('projects.no_due') }}</p><p class="text-sm text-muted dark:text-night-muted">{{ __('projects.assignees') }}: {{ $selectedTask->assignees->pluck('name')->join(', ') ?: __('projects.unassigned') }}</p></div>
            @if ($canEdit && $selectedTask->archived_at)<form method="POST" action="{{ route('projects.tasks.archive', [$board, $selectedTask]) }}" class="mt-5">@csrf @method('PATCH')<button type="submit" class="btn btn-secondary btn-sm">{{ __('projects.restore') }}</button></form>@endif
        @endif

        <section class="mt-8 border-t border-hairline pt-6 dark:border-white/10">
            <h3 class="font-display text-lg font-semibold">{{ __('projects.checklist') }} <span class="text-sm text-muted dark:text-night-muted">{{ $selectedTask->checklistItems->whereNotNull('completed_at')->count() }}/{{ $selectedTask->checklistItems->count() }}</span></h3>
            <div class="mt-3 space-y-2">@foreach ($selectedTask->checklistItems as $item)<div class="flex items-center gap-2 rounded-lg bg-sunk px-3 py-2 text-sm dark:bg-night-raised">@if ($canEdit && ! $selectedTask->archived_at)<form method="POST" action="{{ route('projects.checklist.toggle', [$board, $selectedTask, $item]) }}">@csrf @method('PATCH')<button type="submit" aria-label="{{ __('projects.checklist') }}" class="flex h-5 w-5 items-center justify-center rounded border border-brand text-brand">@if ($item->completed_at)<x-icon name="check" size="h-3.5 w-3.5" />@endif</button></form>@else<span aria-hidden="true">@if ($item->completed_at)<x-icon name="check" size="h-4 w-4" />@else<x-icon name="square" size="h-4 w-4" />@endif</span>@endif<span class="min-w-0 flex-1 break-words {{ $item->completed_at ? 'line-through text-muted' : '' }}">{{ $item->title }}</span>@if ($canEdit && ! $selectedTask->archived_at)<form method="POST" action="{{ route('projects.checklist.destroy', [$board, $selectedTask, $item]) }}">@csrf @method('DELETE')<button type="submit" class="text-danger" aria-label="{{ __('projects.delete_item') }}"><x-icon name="close" size="h-3.5 w-3.5" /></button></form>@endif</div>@endforeach</div>
            @if ($canEdit && ! $selectedTask->archived_at)<form method="POST" action="{{ route('projects.checklist.store', [$board, $selectedTask]) }}" class="mt-3 flex gap-2">@csrf<input name="title" required maxlength="255" placeholder="{{ __('projects.add_item') }}" class="field min-w-0 flex-1 px-3 py-2 text-sm" /><button type="submit" class="btn btn-secondary btn-sm" aria-label="{{ __('projects.add_item') }}"><x-icon name="plus" size="h-4 w-4" /></button></form>@endif
        </section>

        <section class="mt-8 border-t border-hairline pt-6 dark:border-white/10">
            <h3 class="font-display text-lg font-semibold">{{ __('projects.activity') }}</h3>
            @if ($canComment && ! $selectedTask->archived_at)<form method="POST" action="{{ route('projects.comments.store', [$board, $selectedTask]) }}" class="mt-4 space-y-2">@csrf<textarea name="body" required maxlength="10000" rows="3" placeholder="{{ __('projects.comment') }}" class="field w-full px-3 py-2 text-sm"></textarea><button type="submit" class="btn btn-primary btn-sm">{{ __('projects.send_comment') }}</button></form>@endif
            <div class="mt-5 space-y-4">@foreach ($selectedTask->projectEvents as $event)<div class="border-l-2 border-brand/30 pl-3 text-sm"><p><strong>{{ $event->actor?->name ?? __('projects.deleted_user') }}</strong> @if ($event->kind !== 'comment'){{ __('projects.event_'.$event->kind) }}@endif <span class="text-xs text-muted dark:text-night-muted">· {{ $event->created_at?->diffForHumans() }}</span></p>@if ($event->body)<p class="mt-1 whitespace-pre-wrap break-words">{{ $event->body }}</p>@endif</div>@endforeach</div>
        </section>
    </aside>
</div>
