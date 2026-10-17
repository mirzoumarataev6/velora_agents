<section id="board-settings" x-show="settingsOpen" x-cloak class="card card-pad scroll-mt-6 space-y-6">
    <div class="flex items-start justify-between gap-3">
        <div>
        <p class="text-xs font-bold uppercase tracking-[.18em] text-brand">VELORA / WORK</p>
        <h2 class="mt-1 font-display text-xl font-semibold">{{ __('projects.board_settings') }}</h2>
        </div>
        <button type="button" @click="closeSettings()" class="btn btn-ghost btn-sm" aria-label="{{ __('projects.close') }}"><x-icon name="close" size="h-4 w-4" /></button>
    </div>

    {{-- PRJ-004: every write in here redirects back to ?settings=1, scrolled
    straight to this panel (see board.blade.php's init()) rather than the top
    of the page, where the page-level error block above the kanban strip would
    otherwise be the only place a validation error ever showed. Duplicated,
    not moved — a validation error from the task panel still belongs at the
    top, and this one is simply invisible (x-show="settingsOpen") whenever the
    error came from somewhere else. --}}
    @if ($errors->any())
        <div role="alert" class="rounded-2xl border border-danger/20 bg-danger/5 px-5 py-4 text-sm text-danger dark:text-danger-light">
            <ul class="list-inside list-disc space-y-1">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <form method="POST" action="{{ route('projects.boards.update', $board) }}" class="grid gap-3 md:grid-cols-2">
        @csrf @method('PATCH')
        <label class="text-sm font-medium">{{ __('projects.board_name') }}<input name="name" value="{{ $board->name }}" required maxlength="255" class="field mt-1 w-full px-3 py-2" /></label>
        <label class="text-sm font-medium">{{ __('projects.visibility') }}<select name="visibility" class="field mt-1 w-full px-3 py-2"><option value="team" @selected($board->visibility === 'team')>{{ __('projects.visibility_team') }}</option><option value="private" @selected($board->visibility === 'private')>{{ __('projects.visibility_private') }}</option></select></label>
        <label class="text-sm font-medium md:col-span-2">{{ __('projects.board_description') }}<textarea name="description" rows="2" maxlength="3000" class="field mt-1 w-full px-3 py-2">{{ $board->description }}</textarea></label>
        <label class="text-sm font-medium">{{ __('projects.accent') }}<select name="accent" class="field mt-1 w-full px-3 py-2">@foreach (['blue', 'violet', 'emerald', 'amber', 'rose'] as $color)<option value="{{ $color }}" @selected($board->accent === $color)>{{ __('projects.color_'.$color) }}</option>@endforeach</select></label>
        <div class="flex items-end"><button type="submit" class="btn btn-primary">{{ __('projects.save') }}</button></div>
    </form>

    <div class="rule"></div>
    <div class="grid gap-8 lg:grid-cols-2">
        <div class="space-y-3">
            <h3 class="font-semibold">{{ __('projects.add_column') }}</h3>
            @foreach ($columns as $column)
                <div class="rounded-xl border border-hairline p-3 dark:border-white/10">
                    <form method="POST" action="{{ route('projects.columns.update', [$board, $column]) }}" class="flex flex-wrap items-end gap-2">
                        @csrf @method('PATCH')
                        <label class="min-w-40 flex-1 text-xs">{{ __('projects.column_name') }}<input name="name" value="{{ $column->name }}" required maxlength="255" class="field mt-1 w-full px-2 py-1.5 text-sm" /></label>
                        <label class="w-24 text-xs">{{ __('projects.wip_limit') }}<input name="wip_limit" type="number" min="1" max="10000" value="{{ $column->wip_limit }}" class="field mt-1 w-full px-2 py-1.5 text-sm" /></label>
                        <button type="submit" class="btn btn-secondary btn-sm">{{ __('projects.save') }}</button>
                    </form>
                    <div class="mt-2 flex flex-wrap items-center gap-3">
                        <form method="POST" action="{{ route('projects.columns.order', [$board, $column]) }}">@csrf @method('PATCH')<input type="hidden" name="direction" value="up" /><button type="submit" class="inline-flex items-center gap-1 text-xs text-brand hover:underline" aria-label="{{ __('projects.move_column_left') }}"><x-icon name="arrow-left" size="h-3 w-3" />{{ __('projects.move_column_left') }}</button></form>
                        <form method="POST" action="{{ route('projects.columns.order', [$board, $column]) }}">@csrf @method('PATCH')<input type="hidden" name="direction" value="down" /><button type="submit" class="inline-flex items-center gap-1 text-xs text-brand hover:underline" aria-label="{{ __('projects.move_column_right') }}">{{ __('projects.move_column_right') }}<x-icon name="arrow-right" size="h-3 w-3" /></button></form>
                        <form method="POST" action="{{ route('projects.columns.destroy', [$board, $column]) }}">@csrf @method('DELETE')<button type="submit" class="text-xs text-danger hover:underline">{{ __('projects.delete_column') }}</button></form>
                    </div>
                </div>
            @endforeach
        </div>
        <div class="space-y-5">
            <div>
                <h3 class="font-semibold">{{ __('projects.labels') }}</h3>
                <div class="mt-3 flex flex-wrap gap-2">@foreach ($labels as $label)<form method="POST" action="{{ route('projects.labels.destroy', [$board, $label]) }}">@csrf @method('DELETE')<button type="submit" class="project-label project-accent-{{ in_array($label->color, ['blue','violet','emerald','amber','rose'], true) ? $label->color : 'blue' }} inline-flex items-center gap-1" title="{{ __('projects.remove_label') }}">{{ $label->name }}<x-icon name="close" size="h-3 w-3" /></button></form>@endforeach</div>
                <form method="POST" action="{{ route('projects.labels.store', $board) }}" class="mt-3 flex flex-wrap items-end gap-2">@csrf<label class="min-w-36 flex-1 text-xs">{{ __('projects.label_name') }}<input name="name" required maxlength="80" class="field mt-1 w-full px-2 py-1.5 text-sm" /></label><label class="text-xs">{{ __('projects.label_color') }}<select name="color" class="field mt-1 px-2 py-1.5 text-sm">@foreach (['blue', 'violet', 'emerald', 'amber', 'rose'] as $color)<option value="{{ $color }}">{{ __('projects.color_'.$color) }}</option>@endforeach</select></label><button type="submit" class="btn btn-secondary btn-sm">{{ __('projects.add_label') }}</button></form>
            </div>
            @if ($board->visibility === 'private')
                <div>
                    <h3 class="font-semibold">{{ __('projects.board_members') }}</h3>
                    <div class="mt-3 flex flex-wrap gap-2">@foreach ($board->members as $member) @if ($member->id === $board->created_by)<span class="badge badge-neutral">{{ $member->name }}</span>@else<form method="POST" action="{{ route('projects.boards.members.destroy', [$board, $member->id]) }}">@csrf @method('DELETE')<button type="submit" class="badge badge-neutral inline-flex items-center gap-1" title="{{ __('projects.remove_member') }}">{{ $member->name }}<x-icon name="close" size="h-3 w-3" /></button></form>@endif @endforeach</div>
                    <form method="POST" action="{{ route('projects.boards.members.store', $board) }}" class="mt-3 flex flex-wrap gap-2">@csrf<select name="user_id" aria-label="{{ __('projects.add_member') }}" class="field min-w-40 flex-1 px-3 py-2 text-sm">@foreach ($members as $membership)<option value="{{ $membership->user_id }}">{{ $membership->user?->name }}</option>@endforeach</select><button type="submit" class="btn btn-secondary btn-sm">{{ __('projects.add_member') }}</button></form>
                </div>
            @endif
        </div>
    </div>
    <div class="rule"></div>
    @if ($board->slug !== '_inbox')<form method="POST" action="{{ route('projects.boards.archive', $board) }}">@csrf @method('PATCH')<button type="submit" class="btn btn-ghost text-danger">{{ $board->archived_at ? __('projects.restore_board') : __('projects.archive_board') }}</button></form>@endif
</section>
