@extends(request()->header('X-CRM-Partial') === '1' ? 'layouts.crm-partial' : 'layouts.dashboard')

@section('title', __('crm.tasks.title'))

@section('content')
<div x-data="crmTaskBoard({
    items: {{ Js::from($tasks->getCollection()->map(fn ($task) => [
        'id' => $task->id, 'title' => $task->title, 'due_at' => $task->due_at?->translatedFormat('d M, H:i'),
        'overdue' => $task->isOverdue(), 'done' => $task->isDone(), 'priority' => $task->priority,
        'busy' => false, 'completing' => false, 'removing' => false,
        'type' => $task->type, 'customer_name' => $task->customer?->displayName(),
        'customer_url' => $task->customer ? route('crm.customers.show', $task->customer) : null,
        'deal_title' => $task->deal?->title, 'url' => route('crm.tasks.update', $task),
        'delete_url' => route('crm.tasks.destroy', $task),
    ])->values()) }},
    storeUrl: '{{ route('crm.tasks.store') }}',
    customerSearchUrl: '{{ route('crm.customers.search') }}',
    labels: {{ Js::from([
        'created' => __('crm.tasks.created'), 'saved' => __('crm.tasks.saved'), 'deleted' => __('crm.tasks.deleted'),
        'saveFailed' => __('crm.tasks.save_failed'), 'updateFailed' => __('crm.tasks.update_failed'),
        'deleteConfirm' => __('crm.tasks.delete_confirm'),
        'noDue' => __('crm.tasks.no_due'), 'done' => __('crm.tasks.done_label'), 'delete' => __('crm.tasks.delete'),
        'priorities' => __('crm.tasks.priorities'), 'types' => __('crm.tasks.types'),
        'customerSearchEmpty' => __('crm.board.customer_search_empty'),
        'addCustomer' => __('crm.board.add_customer_new'),
        'customerPlaceholder' => __('crm.board.add_customer_placeholder'),
        'customerName' => __('crm.customers.name'),
    ]) }}
})" class="crm-page">
    <div class="page-header">
        <div>
            <h1 class="page-title">{{ __('crm.tasks.heading') }}</h1>
            <p class="page-sub">{{ __('crm.tasks.subheading') }}</p>
        </div>
        <div class="page-actions">
            <button type="button" @click="toggleForm()" class="btn btn-primary btn-sm">{{ __('crm.tasks.add') }}</button>
        </div>
    </div>

    @include('crm.partials.subnav')

    <form method="POST" action="{{ route('crm.tasks.store') }}" @submit.prevent="create()"
        x-show="adding" x-cloak x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0"
        x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0"
        x-transition:leave-end="opacity-0 -translate-y-2" class="card card-pad mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-12">
        @csrf
        <div class="sm:col-span-2 xl:col-span-4">
            <label for="task-title" class="mb-1 block text-sm font-medium">{{ __('crm.tasks.add_title') }}</label>
            <input id="task-title" x-model="form.title" required maxlength="255" class="field w-full px-3 py-2 text-sm" />
        </div>
        <div class="sm:col-span-2 xl:col-span-2">
            <label for="task-due" class="mb-1 block text-sm font-medium">{{ __('crm.tasks.add_due') }}</label>
            <div class="relative">
                <input id="task-due" x-model="dueDisplay" type="text" inputmode="numeric" autocomplete="off"
                    placeholder="{{ __('crm.board.datetime_format_hint') }}" :aria-invalid="!!dueError" class="field w-full px-3 py-2 pr-10 text-sm" />
                <input type="datetime-local" x-ref="duePicker" class="pointer-events-none absolute right-0 top-0 h-1 w-1 opacity-0" @change="dueDisplay = formatDue($event.target.value)">
                <button type="button" @click="if ($refs.duePicker.showPicker) $refs.duePicker.showPicker(); else $refs.duePicker.focus()"
                    class="absolute inset-y-0 right-0 flex w-9 items-center justify-center text-muted hover:text-brand-strong dark:text-night-muted dark:hover:text-brand-light"
                    aria-label="{{ __('crm.board.open_calendar') }}"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4m10-4v4M3 10h18"/></svg></button>
            </div>
            <p x-show="dueError" x-cloak x-text="dueError" role="alert" class="mt-1 text-xs text-danger"></p>
        </div>
        <div class="xl:col-span-2">
            <label for="task-priority" class="mb-1 block text-sm font-medium">{{ __('crm.tasks.priority') }}</label>
            <div id="task-priority" role="radiogroup" aria-label="{{ __('crm.tasks.priority') }}" class="grid grid-cols-2 gap-1.5">
                @foreach (\App\Models\Task::PRIORITIES as $priority)
                    <button type="button" role="radio" :aria-checked="form.priority === '{{ $priority }}'"
                        @click="form.priority = '{{ $priority }}'" :class="priorityOptionClass('{{ $priority }}')"
                        class="flex min-w-0 items-center gap-2 rounded-md border px-2.5 py-2 text-left text-xs font-medium transition-colors duration-150">
                        <span aria-hidden="true" class="h-2 w-2 shrink-0 rounded-full" :class="priorityDotClass('{{ $priority }}')"></span>
                        <span class="truncate">{{ __('crm.tasks.priorities.'.$priority) }}</span>
                    </button>
                @endforeach
            </div>
        </div>
        <div class="xl:col-span-2">
            <label for="task-type" class="mb-1 block text-sm font-medium">{{ __('crm.tasks.type') }}</label>
            <select id="task-type" x-model="form.type" class="field w-full px-3 py-2 text-sm">
                @foreach (\App\Models\Task::TYPES as $type)
                    <option value="{{ $type }}">{{ __('crm.tasks.types.'.$type) }}</option>
                @endforeach
            </select>
        </div>
        <div class="xl:col-span-2">
            <label for="task-customer-search" class="mb-1 block text-sm font-medium">{{ __('crm.tasks.add_customer') }}</label>
            <input type="hidden" x-model="form.customer_id" />
            <div class="relative" @click.outside="customerDropdownOpen = false">
                <input id="task-customer-search" type="search" x-model="customerSearch"
                    @focus="openCustomerOptions()" @input.debounce.220ms="searchCustomers()"
                    @keydown.escape="customerDropdownOpen = false" autocomplete="off"
                    :placeholder="labels.customerPlaceholder" :aria-expanded="customerDropdownOpen"
                    aria-controls="task-customer-options" role="combobox" class="field w-full px-3 py-2 text-sm" />
                <span x-show="customerSearching" x-cloak class="spinner absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2" aria-hidden="true"></span>
                <div id="task-customer-options" x-show="customerDropdownOpen" x-cloak
                    class="absolute z-30 mt-1 max-h-56 w-full overflow-y-auto rounded-md border border-hairline bg-surface p-1 shadow-e3 dark:border-white/10 dark:bg-night-card">
                    <template x-for="customer in customerResults" :key="customer.id">
                        <button type="button" @click="chooseCustomer(customer)"
                            class="flex w-full items-center justify-between gap-3 rounded px-3 py-2 text-left text-sm transition-colors hover:bg-sunk dark:hover:bg-night-raised">
                            <span class="truncate font-medium" x-text="customer.name"></span>
                            <span class="shrink-0 text-xs text-muted dark:text-night-muted" x-text="customer.phone || ''"></span>
                        </button>
                    </template>
                    <button type="button" x-show="canCreateCustomer()" @click="chooseNewCustomer()"
                        class="mt-1 w-full border-t border-hairline px-3 py-2 text-left text-sm font-medium text-brand hover:bg-brand/5 dark:border-white/10">
                        <span x-text="labels.addCustomer"></span> «<span x-text="customerSearch.trim()"></span>»
                    </button>
                    <p x-show="customerSearch.trim().length >= 2 && customerResults.length === 0" x-cloak
                        class="px-3 py-2 text-xs text-muted dark:text-night-muted" x-text="labels.customerSearchEmpty"></p>
                    <button type="button" @click="clearCustomer()"
                        class="w-full rounded px-3 py-2 text-left text-xs text-muted hover:bg-sunk dark:text-night-muted dark:hover:bg-night-raised">
                        {{ __('crm.tasks.no_customer') }}
                    </button>
                </div>
            </div>
            <p x-show="form.customer_id" x-cloak class="mt-1.5 truncate text-xs text-brand">
                <span aria-hidden="true">✓</span> <span x-text="customerSearch"></span>
            </p>
            <div x-show="creatingCustomer" x-cloak class="mt-2">
                <label for="task-new-customer-name" class="mb-1 block text-xs font-medium">{{ __('crm.customers.name') }}</label>
                <input id="task-new-customer-name" x-model="form.new_customer_name" maxlength="255"
                    :required="creatingCustomer" :disabled="!creatingCustomer" class="field w-full px-3 py-2 text-sm" />
            </div>
        </div>
        <div class="flex items-end justify-end gap-2 sm:col-span-2 xl:col-span-12">
            <button type="button" @click="cancelForm()" class="btn btn-ghost btn-sm">{{ __('crm.tasks.cancel') }}</button>
            <button type="submit" :disabled="saving" class="btn btn-primary btn-sm disabled:opacity-60">
                <span x-text="saving ? @js(__('crm.tasks.saving')) : @js(__('crm.tasks.add_submit'))"></span>
            </button>
        </div>
    </form>

    <div class="mt-5 flex flex-wrap items-center gap-2">
        @foreach (['open', 'today', 'overdue', 'done'] as $filter)
            <a href="{{ route('crm.tasks.index', array_filter(['filter' => $filter, 'workspace' => $activeWorkspace?->id])) }}"
                data-crm-nav class="rounded-full border px-4 py-1.5 text-sm font-medium transition-colors {{ $activeFilter === $filter ? 'border-brand bg-brand/10 text-brand' : 'border-black/10 text-muted hover:bg-black/5 dark:border-white/10 dark:text-night-muted dark:hover:bg-white/5' }}">
                {{ __('crm.tasks.filters.'.$filter) }}
                @if ($filter === 'overdue' && $counts['overdue'] > 0)<span class="badge badge-danger tnum">{{ $counts['overdue'] }}</span>@endif
            </a>
        @endforeach
        <form method="GET" action="{{ route('crm.tasks.index') }}" data-crm-filter-form class="ml-auto flex items-center gap-2">
            <input type="hidden" name="filter" value="{{ $activeFilter }}" />
            <label for="task-workspace" class="sr-only">{{ __('crm.tasks.workspace_filter') }}</label>
            <select id="task-workspace" name="workspace" class="field max-w-52 px-3 py-2 text-sm">
                <option value="">{{ __('crm.workspaces.all') }}</option>
                @foreach ($workspaces as $workspace)
                    <option value="{{ $workspace->id }}" @selected($activeWorkspace?->id === $workspace->id)>{{ $workspace->displayName() }}</option>
                @endforeach
            </select>
        </form>
    </div>

    <div class="card mt-4 divide-y divide-black/5 overflow-hidden dark:divide-white/5" x-show="items.length" x-cloak>
        <template x-for="task in items" :key="task.id">
            <article class="crm-task-row flex max-h-48 items-center gap-2 overflow-hidden px-2 py-3 transition-[max-height,padding,opacity,transform] duration-300 ease-out sm:gap-3 sm:px-4"
                :class="task.removing ? '-translate-x-3 opacity-0' : 'opacity-100'"
                :style="task.removing ? { maxHeight: '0', paddingTop: '0', paddingBottom: '0' } : {}">
                <button type="button" @click="toggle(task)" :aria-label="labels.done" :aria-pressed="task.done" :aria-busy="task.busy === true"
                    class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md transition-colors hover:bg-sunk focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand dark:hover:bg-night-raised">
                    <span class="flex h-5 w-5 items-center justify-center rounded-md border transition-all duration-200"
                        :class="task.done || task.completing ? 'border-brand bg-brand text-white' : 'border-black/20 dark:border-white/20'">
                        <svg x-show="task.done || task.completing" class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                    </span>
                </button>
                <div class="min-w-0 flex-1">
                    <p class="break-words text-sm transition-colors duration-200"
                        :class="task.done || task.completing ? 'text-muted dark:text-night-muted' : 'text-ink dark:text-night-ink'">
                        <span class="crm-task-title-text" :class="task.done || task.completing ? 'crm-task-title-text--done' : ''" x-text="task.title"></span>
                    </p>
                    <p class="mt-1 flex flex-wrap items-center gap-2 text-xs text-muted dark:text-night-muted">
                        <span :class="task.overdue ? 'font-medium text-danger' : ''" x-text="task.due_at || labels.noDue"></span>
                        <span class="badge badge-neutral" x-text="labels.types[task.type]"></span>
                        <span class="badge inline-flex items-center gap-1.5" :class="priorityClass(task.priority)">
                            <span aria-hidden="true" class="h-1.5 w-1.5 rounded-full" :class="priorityDotClass(task.priority)"></span>
                            <span x-text="labels.priorities[task.priority]"></span>
                        </span>
                        <a x-show="task.customer_url" :href="task.customer_url" class="hover:underline" x-text="task.customer_name"></a>
                        <span x-show="task.deal_title" x-text="task.deal_title ? '· ' + task.deal_title : ''"></span>
                        <span x-show="task.overdue" class="badge badge-danger">{{ __('crm.tasks.overdue') }}</span>
                    </p>
                </div>
                <button type="button" @click="remove(task)" class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg text-muted transition-colors hover:bg-danger/10 hover:text-danger focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand dark:text-night-muted" :aria-label="labels.delete">✕</button>
            </article>
        </template>
    </div>

    <div class="empty-state mt-6" x-show="items.length === 0" x-cloak>
        <div class="empty-state-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg></div>
        <p class="empty-state-title">{{ __('crm.tasks.empty_title') }}</p>
        <p class="empty-state-text">{{ __('crm.tasks.empty_description') }}</p>
    </div>

    @if ($tasks->hasPages())<div class="mt-6">{{ $tasks->links() }}</div>@endif
</div>

<script data-crm-page-script>
function crmTaskBoard({ items, storeUrl, customerSearchUrl, labels }) {
    return {
        items, storeUrl, customerSearchUrl, labels, adding: false, saving: false,
        form: { title: '', due_at: '', customer_id: '', new_customer_name: '', priority: 'normal', type: 'follow_up' },
        dueDisplay: '', dueError: '', dateEuropean: @js(app()->getLocale() !== 'en'),
        formatDue(iso) {
            if (!iso) return '';
            const match = String(iso).match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}:\d{2})/);
            return match ? (this.dateEuropean ? `${match[3]}.${match[2]}.${match[1]}` : `${match[1]}-${match[2]}-${match[3]}`) + ` ${match[4]}` : String(iso);
        },
        parseDue() {
            if (!this.dueDisplay.trim()) return '';
            const match = this.dateEuropean
                ? this.dueDisplay.trim().match(/^(\d{2})\.(\d{2})\.(\d{4})\s+(\d{2}):(\d{2})$/)
                : this.dueDisplay.trim().match(/^(\d{4})-(\d{2})-(\d{2})\s+(\d{2}):(\d{2})$/);
            if (!match) return null;
            const iso = this.dateEuropean ? `${match[3]}-${match[2]}-${match[1]}` : `${match[1]}-${match[2]}-${match[3]}`;
            const hour = Number(match[4]), minute = Number(match[5]);
            const date = new Date(`${iso}T00:00:00Z`);
            return Number.isNaN(date.getTime()) || date.toISOString().slice(0, 10) !== iso || hour > 23 || minute > 59 ? null : `${iso}T${match[4]}:${match[5]}`;
        },
        customerSearch: '', customerResults: [], customerDropdownOpen: false, creatingCustomer: false, customerSearching: false,
        customerSearchSequence: 0, customerSearchRequest: null,
        toggleForm() { this.adding = !this.adding; if (this.adding) this.$nextTick(() => document.getElementById('task-title')?.focus()); },
        cancelForm() {
            this.adding = false;
            this.customerSearchRequest?.abort();
            this.customerSearch = ''; this.customerResults = []; this.customerDropdownOpen = false; this.creatingCustomer = false; this.customerSearching = false;
            this.form = { title: '', due_at: '', customer_id: '', new_customer_name: '', priority: 'normal', type: 'follow_up' };
            this.dueDisplay = ''; this.dueError = '';
        },
        priorityClass(value) { return ({ low: 'badge-neutral', normal: 'badge-brand', high: 'badge-warning', urgent: 'badge-danger' })[value] || 'badge-neutral'; },
        priorityDotClass(value) { return ({ low: 'bg-slate-400', normal: 'bg-brand', high: 'bg-amber-500', urgent: 'bg-red-500' })[value] || 'bg-slate-400'; },
        priorityOptionClass(value) {
            const selected = this.form.priority === value;
            const colors = {
                low: selected ? 'border-slate-400 bg-slate-100 text-slate-700 dark:border-slate-400/50 dark:bg-slate-400/10 dark:text-slate-200' : 'border-hairline text-muted hover:bg-sunk dark:border-white/10 dark:text-night-muted dark:hover:bg-night-raised',
                normal: selected ? 'border-brand bg-brand/10 text-brand' : 'border-hairline text-muted hover:bg-sunk dark:border-white/10 dark:text-night-muted dark:hover:bg-night-raised',
                high: selected ? 'border-amber-500 bg-amber-50 text-amber-800 dark:border-amber-400/50 dark:bg-amber-400/10 dark:text-amber-200' : 'border-hairline text-muted hover:bg-sunk dark:border-white/10 dark:text-night-muted dark:hover:bg-night-raised',
                urgent: selected ? 'border-red-500 bg-red-50 text-red-700 dark:border-red-400/50 dark:bg-red-400/10 dark:text-red-200' : 'border-hairline text-muted hover:bg-sunk dark:border-white/10 dark:text-night-muted dark:hover:bg-night-raised',
            };
            return colors[value] || colors.normal;
        },
        async readableFailure(response) {
            const body = await response.json().catch(() => null);
            return Object.values(body?.errors || {}).flat()[0] || labels.saveFailed;
        },
        openCustomerOptions() { this.customerDropdownOpen = true; this.searchCustomers(); },
        async searchCustomers() {
            const term = this.customerSearch.trim();
            this.form.customer_id = '';
            this.form.new_customer_name = '';
            this.creatingCustomer = false;
            this.customerDropdownOpen = true;
            this.customerResults = [];
            const sequence = ++this.customerSearchSequence;
            this.customerSearchRequest?.abort();
            if (term.length < 2) { this.customerResults = []; this.customerSearching = false; return; }
            this.customerSearchRequest = new AbortController();
            this.customerSearching = true;
            const url = new URL(this.customerSearchUrl, window.location.origin);
            url.searchParams.set('q', term);
            try {
                const response = await fetch(url, { headers: { Accept: 'application/json' }, signal: this.customerSearchRequest.signal });
                if (!response.ok) throw new Error('search failed');
                const data = await response.json();
                if (sequence === this.customerSearchSequence) this.customerResults = data.customers || [];
            } catch (error) {
                if (error.name !== 'AbortError' && sequence === this.customerSearchSequence) this.customerResults = [];
            } finally {
                if (sequence === this.customerSearchSequence) this.customerSearching = false;
            }
        },
        chooseCustomer(customer) {
            this.form.customer_id = String(customer.id);
            this.form.new_customer_name = '';
            this.customerSearch = customer.name;
            this.creatingCustomer = false; this.customerDropdownOpen = false; this.customerSearching = false;
        },
        canCreateCustomer() {
            const term = this.customerSearch.trim();
            return term.length >= 2 && !this.customerResults.some(customer => customer.name.toLocaleLowerCase() === term.toLocaleLowerCase());
        },
        chooseNewCustomer() {
            this.form.customer_id = '';
            this.form.new_customer_name = this.customerSearch.trim();
            this.creatingCustomer = true; this.customerDropdownOpen = false; this.customerSearching = false;
        },
        clearCustomer() {
            this.form.customer_id = ''; this.form.new_customer_name = '';
            this.customerSearch = ''; this.creatingCustomer = false; this.customerDropdownOpen = false; this.customerSearching = false;
        },
        async create() {
            if (this.saving || !this.form.title.trim() || (this.creatingCustomer && !this.form.new_customer_name.trim())) return;
            this.dueError = '';
            const due = this.parseDue();
            if (due === null) { this.dueError = @js(__('crm.board.invalid_datetime')); return; }
            this.form.due_at = due;
            this.saving = true;
            try {
                const payload = { ...this.form, title: this.form.title.trim(), new_customer_name: this.form.new_customer_name.trim() || null };
                const response = await fetch(this.storeUrl, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }, body: JSON.stringify(payload) });
                if (!response.ok) throw new Error(await this.readableFailure(response));
                const { task } = await response.json();
                if (['open', 'today'].includes(new URLSearchParams(location.search).get('filter') || 'open')) {
                    this.items.unshift({ ...task, busy: false, completing: false, removing: false });
                }
                this.cancelForm(); window.showToast?.(labels.created, 'success');
            } catch (error) { window.showToast?.(error.message || labels.saveFailed, 'error'); }
            finally { this.saving = false; }
        },
        async toggle(task) {
            if (task.busy === true) return;
            task.busy = true; task.completing = !task.done;
            try {
                const response = await fetch(task.url, { method: 'PATCH', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }, body: JSON.stringify({ done: !task.done }) });
                const contentType = response.headers.get('content-type') || '';
                const result = contentType.includes('application/json') ? await response.json() : null;
                // A session redirect can end as HTTP 200 with the login HTML.
                // Treating that as success used to animate the task away even
                // though the database had never recorded completion.
                if (!response.ok || response.redirected || !result || typeof result.done !== 'boolean') {
                    throw new Error(Object.values(result?.errors || {}).flat()[0] || labels.updateFailed);
                }
                task.done = result.done; task.completing = false;
                window.showToast?.(labels.saved, 'success');
                const currentFilter = new URLSearchParams(location.search).get('filter') || 'open';
                if ((task.done && currentFilter !== 'done') || (!task.done && currentFilter === 'done')) {
                    task.removing = true; setTimeout(() => { this.items = this.items.filter(item => item.id !== task.id); }, 320);
                }
            } catch (error) {
                task.completing = false;
                window.showToast?.(error instanceof TypeError ? labels.updateFailed : (error.message || labels.updateFailed), 'error');
            }
            finally { task.busy = false; }
        },
        async remove(task) {
            if (!confirm(labels.deleteConfirm) || task.busy === true) return;
            task.busy = true;
            try {
                const response = await fetch(task.delete_url, { method: 'DELETE', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content } });
                if (!response.ok) throw new Error(await this.readableFailure(response));
                task.removing = true; setTimeout(() => { this.items = this.items.filter(item => item.id !== task.id); }, 320);
                window.showToast?.(labels.deleted, 'success');
            } catch (error) { window.showToast?.(error.message || labels.saveFailed, 'error'); }
            finally { task.busy = false; }
        },
    };
}
</script>
@endsection
