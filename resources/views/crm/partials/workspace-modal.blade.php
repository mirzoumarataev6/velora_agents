<div x-show="workspaceModal" x-cloak
    class="fixed inset-0 z-[60] flex items-end justify-center bg-black/45 p-0 sm:items-center sm:p-4"
    @click.self="workspaceModal = false" @keydown.escape.window="workspaceModal = false">
    <section class="w-full max-w-lg border border-hairline bg-surface p-5 shadow-e3 dark:border-white/10 dark:bg-night-card sm:p-6"
        role="dialog" aria-modal="true" :aria-label="workspaceMode === 'create' ? @js(__('crm.workspaces.create_title')) : @js(__('crm.workspaces.edit_title'))">
        <header class="mb-5 flex items-start justify-between gap-4 border-b border-hairline pb-4 dark:border-white/10">
            <div>
                <h2 class="text-lg font-semibold" x-text="workspaceMode === 'create' ? @js(__('crm.workspaces.create_title')) : @js(__('crm.workspaces.edit_title'))"></h2>
                <p class="mt-1 text-sm text-muted dark:text-night-muted">{{ __('crm.workspaces.name_placeholder') }}</p>
            </div>
            <button type="button" @click="workspaceModal = false" class="btn btn-ghost btn-sm shrink-0"
                aria-label="{{ __('crm.workspaces.cancel') }}">✕</button>
        </header>

        <form x-show="workspaceMode === 'create'" method="POST" action="{{ route('crm.workspaces.store') }}" class="space-y-4">
            @csrf
            <input type="hidden" name="origin" :value="filters.origin" />
            <input type="hidden" name="workspace_form" value="1" />
            <input type="hidden" name="workspace_mode" value="create" />
            <div>
                <label for="workspace-new-name" class="mb-1.5 block text-sm font-medium">{{ __('crm.workspaces.name') }}</label>
                <input id="workspace-new-name" name="name" x-model="workspaceName" required minlength="2" maxlength="80"
                    class="field w-full !rounded-md px-3 py-2.5 text-sm" />
                @error('name')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
            </div>
            <div>
                <p class="mb-1.5 text-sm font-medium">{{ __('crm.workspaces.color') }}</p>
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-3" role="radiogroup" aria-label="{{ __('crm.workspaces.color') }}">
                    @foreach (\App\Models\CrmWorkspace::COLORS as $color)
                        <label class="flex cursor-pointer items-center gap-2 border border-hairline px-3 py-2.5 text-sm transition-colors focus-within:outline focus-within:outline-2 focus-within:outline-brand dark:border-white/10"
                            :class="workspaceColor === '{{ $color }}' ? 'border-brand bg-brand/5 text-brand' : 'hover:bg-sunk dark:hover:bg-night-raised'">
                            <input class="sr-only" type="radio" name="color" value="{{ $color }}" x-model="workspaceColor" @checked($color === 'blue') />
                            <span aria-hidden="true" class="h-3.5 w-3.5 shrink-0 {{ ['blue' => 'bg-brand', 'violet' => 'bg-purple-500', 'green' => 'bg-green-500', 'amber' => 'bg-amber-500', 'rose' => 'bg-rose-500', 'slate' => 'bg-slate-500'][$color] }}"></span>
                            <span>{{ __('crm.workspaces.colors.'.$color) }}</span>
                        </label>
                    @endforeach
                </div>
                @error('color')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
            </div>
            <footer class="flex justify-end gap-2 border-t border-hairline pt-4 dark:border-white/10">
                <button type="button" @click="workspaceModal = false" class="btn btn-secondary btn-sm">{{ __('crm.workspaces.cancel') }}</button>
                <button type="submit" class="btn btn-primary btn-sm">{{ __('crm.workspaces.create') }}</button>
            </footer>
        </form>

        <div x-show="workspaceMode === 'edit'" x-cloak class="space-y-4">
            <form method="POST" :action="workspaceUpdateUrlBase.replace('__ID__', workspaceEditId)" class="space-y-4">
                @csrf
                @method('PATCH')
                <input type="hidden" name="origin" :value="filters.origin" />
                <input type="hidden" name="workspace_form" value="1" />
                <input type="hidden" name="workspace_mode" value="edit" />
                <input type="hidden" name="workspace_id" :value="workspaceEditId" />
                <div>
                    <label for="workspace-edit-name" class="mb-1.5 block text-sm font-medium">{{ __('crm.workspaces.name') }}</label>
                    <input id="workspace-edit-name" name="name" x-model="workspaceName" required minlength="2" maxlength="80"
                        class="field w-full !rounded-md px-3 py-2.5 text-sm" />
                    @error('name')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
                </div>
                <div>
                    <p class="mb-1.5 text-sm font-medium">{{ __('crm.workspaces.color') }}</p>
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-3" role="radiogroup" aria-label="{{ __('crm.workspaces.color') }}">
                        @foreach (\App\Models\CrmWorkspace::COLORS as $color)
                            <label class="flex cursor-pointer items-center gap-2 border border-hairline px-3 py-2.5 text-sm transition-colors focus-within:outline focus-within:outline-2 focus-within:outline-brand dark:border-white/10"
                                :class="workspaceColor === '{{ $color }}' ? 'border-brand bg-brand/5 text-brand' : 'hover:bg-sunk dark:hover:bg-night-raised'">
                                <input class="sr-only" type="radio" name="color" value="{{ $color }}" x-model="workspaceColor" />
                                <span aria-hidden="true" class="h-3.5 w-3.5 shrink-0 {{ ['blue' => 'bg-brand', 'violet' => 'bg-purple-500', 'green' => 'bg-green-500', 'amber' => 'bg-amber-500', 'rose' => 'bg-rose-500', 'slate' => 'bg-slate-500'][$color] }}"></span>
                                <span>{{ __('crm.workspaces.colors.'.$color) }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('color')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
                </div>
                <footer class="flex flex-wrap items-center justify-between gap-2 border-t border-hairline pt-4 dark:border-white/10">
                    <button type="button" @click="workspaceModal = false" class="btn btn-secondary btn-sm">{{ __('crm.workspaces.cancel') }}</button>
                    <button type="submit" class="btn btn-primary btn-sm">{{ __('crm.workspaces.save') }}</button>
                </footer>
            </form>
            <form method="POST" :action="workspaceDeleteUrlBase.replace('__ID__', workspaceEditId)"
                @submit="if (!confirm(labels.workspaceDeleteConfirm)) $event.preventDefault()" class="border-t border-hairline pt-4 dark:border-white/10">
                @csrf
                @method('DELETE')
                <input type="hidden" name="origin" :value="filters.origin" />
                <button type="submit" class="btn btn-ghost btn-sm text-danger">{{ __('crm.workspaces.delete') }}</button>
            </form>
        </div>
    </section>
</div>
