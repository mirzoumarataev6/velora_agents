@php
    // archive()'s own redirect rebuilds the URL from route() rather than
    // back() (see ProjectTaskController::archive — it closes the panel, so
    // there's no task id to send back to), which means q/filter/page only
    // survive if THIS request carries them — back()'s Referer trick doesn't
    // apply. Put them on the form's action the same way $boardLink() does.
    //
    // Same two guards as $boardLink(): request()->query() (this view is GET
    // only, so there is no body to leak, but spelling it as query() keeps that
    // explicit) with `board`/`task` stripped and re-added LAST, so neither a
    // stray ?board= nor ?task= in the current URL can outrank the real route
    // parameters this form must submit to.
    $archiveUrl = route('projects.tasks.archive', array_merge(
        \Illuminate\Support\Arr::except(request()->query(), ['board', 'task']),
        ['board' => $board, 'task' => $selectedTask]
    ));

    // One line of translated detail per thing an 'updated'/'moved' event
    // actually changed — "updated the task" alone forced a reader to open
    // the task's edit history elsewhere to find out what. ids in `details`
    // (assignees/labels) resolve through $eventUserNames/$eventLabelNames,
    // batched once in BoardController rather than queried per event here;
    // a stray id whose row has since been deleted falls back to
    // deleted_user/deleted_label, the same convention already used for a
    // departed actor.
    $eventDetailLines = function ($event) use ($columns, $eventUserNames, $eventLabelNames) {
        $details = $event->details ?? [];
        $lines = [];
        if ($event->kind === 'updated') {
            if (! empty($details['fields'])) {
                $fields = array_map(fn ($field) => __('projects.activity_fields.'.$field), $details['fields']);
                $lines[] = __('projects.activity_log.changed_fields', ['fields' => implode(', ', $fields)]);
            }
            foreach (['assignees_added', 'assignees_removed'] as $key) {
                if (! empty($details[$key])) {
                    $names = array_map(fn ($id) => $eventUserNames[$id] ?? __('projects.deleted_user'), $details[$key]);
                    $lines[] = __('projects.activity_log.'.$key, ['names' => implode(', ', $names)]);
                }
            }
            foreach (['labels_added', 'labels_removed'] as $key) {
                if (! empty($details[$key])) {
                    $names = array_map(fn ($id) => $eventLabelNames[$id] ?? __('projects.deleted_label'), $details[$key]);
                    $lines[] = __('projects.activity_log.'.$key, ['names' => implode(', ', $names)]);
                }
            }
        } elseif ($event->kind === 'moved') {
            $lines[] = __('projects.activity_log.moved_columns', [
                'from' => $columns->firstWhere('id', $details['from_column_id'] ?? null)?->name ?? '—',
                'to' => $columns->firstWhere('id', $details['to_column_id'] ?? null)?->name ?? '—',
            ]);
        }

        return $lines;
    };
@endphp
<div class="fixed inset-0 z-[80] flex justify-end"
    {{-- PHASE 2 item 8: a dialog that opens without moving focus into it, or
    that lets Tab walk straight out to the board/sidebar behind it, is a
    keyboard trap of the OTHER kind — a sighted mouse user never notices
    the board scrolling behind the overlay, but a keyboard/screen-reader
    user tabs "through" a dialog that visually still owns the screen.
    x-init moves focus onto the X the instant this node exists (every
    open is a fresh Turbo visit that re-renders this whole partial, so
    there is no "already open, don't refocus" case to guard against).
    trap() wraps Tab/Shift+Tab at the dialog's own first/last focusable
    element — scoped to $root (this div, backdrop link included) rather
    than to <aside> alone, so the backdrop stays a legitimate keyboard
    path to closing, same as a click on it already is. Focus RETURNING to
    the card that opened this, on close, is handled in app.js
    (turbo:load) — not here, because closing is a navigation AWAY from
    this element, which will no longer exist by the time that needs to
    happen. --}}
    x-data="{
        trap(event) {
            const focusable = Array.from($root.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex=\'-1\'])')).filter((el) => el.offsetParent !== null);
            if (! focusable.length) return;
            const first = focusable[0];
            const last = focusable[focusable.length - 1];
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
            else if (! event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
        },
    }"
    x-init="$nextTick(() => $refs.closePanel.focus())"
    @keydown.escape.window="$refs.closePanel.click()"
    @keydown.tab="trap($event)">
    {{-- Esc, the X and the backdrop all resolve to the one $boardLink() href
    (board.blade.php) and the X link below, so Esc clicks that real <a> and
    gets Turbo's fast visit — PRJ-006 — rather than forcing a full page reload. --}}
    <a href="{{ $boardLink() }}" data-preserve-scroll class="absolute inset-0 bg-ink/60 backdrop-blur-[2px]" aria-label="{{ __('projects.close') }}"></a>
    <aside role="dialog" aria-modal="true" aria-labelledby="project-task-title" class="relative flex h-full w-full max-w-2xl flex-col overflow-y-auto bg-surface p-5 shadow-2xl dark:bg-night-card sm:p-8">
        <div class="flex items-start justify-between gap-4">
            <div class="min-w-0"><p class="text-xs font-bold uppercase tracking-[.16em] text-brand">{{ $board->name }} / {{ $selectedTask->projectColumn?->name }}</p><h2 id="project-task-title" class="mt-2 break-words font-display text-2xl font-semibold">{{ $selectedTask->title }}</h2></div>
            <a x-ref="closePanel" href="{{ $boardLink() }}" data-preserve-scroll class="btn btn-ghost btn-sm" aria-label="{{ __('projects.close') }}"><x-icon name="close" size="h-4 w-4" /></a>
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
                    {{-- Its own x-data: customerId/dealId only exist to filter the deal
                    list below and clear a now-mismatched selection, nothing the rest of
                    the form needs. A stray deal left over from a different customer is
                    worse than an empty field — the controller's checkCrmAccess() would
                    reject the mismatch at save time anyway, but silently, after a
                    confusing round trip; clearing it here is the same rule, visible
                    immediately instead of as a validation error. The selected option is
                    always kept visible (third x-show clause) so switching the customer
                    dropdown and switching it right back never makes the deal that was
                    already chosen disappear from its own list. --}}
                    <fieldset class="grid gap-3 sm:grid-cols-2"
                        x-data="{ customerId: '{{ (string) ($selectedTask->customer_id ?? '') }}', dealId: '{{ (string) ($selectedTask->deal_id ?? '') }}' }"
                        x-effect="const chosen = dealId !== '' ? $refs.dealSelect.querySelector(`option[value='${dealId}']`) : null; if (chosen && chosen.dataset.customer !== '' && chosen.dataset.customer !== customerId) { dealId = '' }">
                        <legend class="mb-2 text-sm font-medium">{{ __('projects.linked_crm') }}</legend>
                        <label class="text-xs">{{ __('projects.customer') }}<select name="customer_id" x-model="customerId" class="field mt-1 w-full px-2 py-2 text-sm"><option value="">{{ __('projects.none') }}</option>@foreach ($customers as $customer)<option value="{{ $customer->id }}">{{ $customer->display_label }}</option>@endforeach</select></label>
                        <label class="text-xs">{{ __('projects.deal') }}<select name="deal_id" x-ref="dealSelect" x-model="dealId" class="field mt-1 w-full px-2 py-2 text-sm"><option value="">{{ __('projects.none') }}</option>@foreach ($deals as $deal)<option value="{{ $deal->id }}" data-customer="{{ $deal->customer_id }}" x-show="customerId === '' || $el.dataset.customer === customerId || $el.value === dealId">{{ $deal->display_label }}</option>@endforeach</select></label>
                    </fieldset>
                @endif
                <button type="submit" class="btn btn-primary">{{ __('projects.save') }}</button>
            </form>
            <div class="mt-6 flex flex-wrap gap-2 border-t border-hairline pt-5 dark:border-white/10">
                <form method="POST" action="{{ route('projects.tasks.move', [$board, $selectedTask]) }}" class="flex flex-1 flex-wrap items-center gap-2">@csrf @method('PATCH')<label for="move-column" class="text-sm font-medium">{{ __('projects.move_to') }}</label><select id="move-column" name="column_id" class="field min-w-36 flex-1 px-3 py-2 text-sm">@foreach ($columns as $column)<option value="{{ $column->id }}" @selected($selectedTask->project_column_id === $column->id)>{{ $column->name }}</option>@endforeach</select><button type="submit" class="btn btn-secondary btn-sm">{{ __('projects.move') }}</button></form>
                <form method="POST" action="{{ $archiveUrl }}">@csrf @method('PATCH')<button type="submit" class="btn btn-ghost btn-sm text-danger">{{ $selectedTask->archived_at ? __('projects.restore') : __('projects.archive') }}</button></form>
            </div>
        @else
            <div class="mt-6 space-y-4"><p class="whitespace-pre-wrap text-sm leading-6">{{ $selectedTask->description }}</p><p class="text-sm text-muted dark:text-night-muted">{{ __('projects.due_at') }}: {{ $selectedTask->due_at?->translatedFormat('d M Y H:i') ?? __('projects.no_due') }}</p><p class="text-sm text-muted dark:text-night-muted">{{ __('projects.assignees') }}: {{ $selectedTask->assignees->pluck('name')->join(', ') ?: __('projects.unassigned') }}</p></div>
            @if ($canEdit && $selectedTask->archived_at)<form method="POST" action="{{ $archiveUrl }}" class="mt-5">@csrf @method('PATCH')<button type="submit" class="btn btn-secondary btn-sm">{{ __('projects.restore') }}</button></form>@endif
        @endif

        <section class="mt-8 border-t border-hairline pt-6 dark:border-white/10">
            <h3 class="font-display text-lg font-semibold">{{ __('projects.checklist') }} <span class="text-sm text-muted dark:text-night-muted">{{ $selectedTask->checklistItems->whereNotNull('completed_at')->count() }}/{{ $selectedTask->checklistItems->count() }}</span></h3>
            <div class="mt-3 space-y-2">@foreach ($selectedTask->checklistItems as $item)<div class="flex items-center gap-2 rounded-lg bg-sunk px-3 py-2 text-sm dark:bg-night-raised">@if ($canEdit && ! $selectedTask->archived_at)<form method="POST" action="{{ route('projects.checklist.toggle', [$board, $selectedTask, $item]) }}">@csrf @method('PATCH')<button type="submit" aria-label="{{ $item->completed_at ? __('projects.checklist_mark_open', ['title' => $item->title]) : __('projects.checklist_mark_done', ['title' => $item->title]) }}" class="flex h-5 w-5 items-center justify-center rounded border border-brand text-brand">@if ($item->completed_at)<x-icon name="check" size="h-3.5 w-3.5" />@endif</button></form>@else<span aria-hidden="true">@if ($item->completed_at)<x-icon name="check" size="h-4 w-4" />@else<x-icon name="square" size="h-4 w-4" />@endif</span>@endif<span class="min-w-0 flex-1 break-words {{ $item->completed_at ? 'line-through text-muted' : '' }}">{{ $item->title }}</span>@if ($canEdit && ! $selectedTask->archived_at)<form method="POST" action="{{ route('projects.checklist.destroy', [$board, $selectedTask, $item]) }}">@csrf @method('DELETE')<button type="submit" class="text-danger" aria-label="{{ __('projects.delete_item') }}"><x-icon name="close" size="h-3.5 w-3.5" /></button></form>@endif</div>@endforeach</div>
            @if ($canEdit && ! $selectedTask->archived_at)<form method="POST" action="{{ route('projects.checklist.store', [$board, $selectedTask]) }}" class="mt-3 flex gap-2">@csrf<input name="title" required maxlength="255" placeholder="{{ __('projects.add_item') }}" class="field min-w-0 flex-1 px-3 py-2 text-sm" /><button type="submit" class="btn btn-secondary btn-sm" aria-label="{{ __('projects.add_item') }}"><x-icon name="plus" size="h-4 w-4" /></button></form>@endif
        </section>

        <section class="mt-8 border-t border-hairline pt-6 dark:border-white/10">
            <h3 class="font-display text-lg font-semibold">{{ __('projects.activity') }}</h3>
            @if ($canComment && ! $selectedTask->archived_at)<form method="POST" action="{{ route('projects.comments.store', [$board, $selectedTask]) }}" class="mt-4 space-y-2">@csrf<textarea name="body" required maxlength="10000" rows="3" placeholder="{{ __('projects.comment') }}" class="field w-full px-3 py-2 text-sm"></textarea><button type="submit" class="btn btn-primary btn-sm">{{ __('projects.send_comment') }}</button></form>@endif
            <div class="mt-5 space-y-4">@foreach ($selectedTask->projectEvents as $event)<div class="border-l-2 border-brand/30 pl-3 text-sm"><p><strong>{{ $event->actor?->name ?? __('projects.deleted_user') }}</strong> @if ($event->kind !== 'comment'){{ __('projects.event_'.$event->kind) }}@endif <span class="text-xs text-muted dark:text-night-muted">· {{ $event->created_at?->diffForHumans() }}</span></p>@if ($event->body)<p class="mt-1 whitespace-pre-wrap break-words">{{ $event->body }}</p>@endif @foreach ($eventDetailLines($event) as $line)<p class="mt-1 text-xs text-muted dark:text-night-muted">{{ $line }}</p>@endforeach</div>@endforeach</div>
        </section>
    </aside>
</div>
