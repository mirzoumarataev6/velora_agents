@extends('layouts.dashboard')

@section('title', __('crm.board.title'))

@section('content')
<div x-data="dealList({
        deals: {{ Js::from($deals) }},
        leads: {{ Js::from($leads) }},
        leadsBucket: @js($leadsBucket),
        stages: {{ Js::from($stages) }},
        pagination: {{ Js::from($pagination) }},
        leadsCount: @js($leadsCount),
        overview: {{ Js::from($summary) }},
        cancelledStage: @js($cancelledStage),
        filters: {{ Js::from($filters) }},
        pollUrlBase: '{{ route('crm.deals.poll') }}',
        listUrlBase: '{{ route('crm.board') }}',
        updateUrlBase: '{{ route('crm.deals.update', ['deal' => '__ID__']) }}',
        activityUrlBase: '{{ route('crm.deals.activity', ['deal' => '__ID__']) }}',
        deleteUrlBase: '{{ route('crm.deals.destroy', ['deal' => '__ID__']) }}',
        taskStoreUrl: '{{ route('crm.tasks.store') }}',
        customerUrlBase: '{{ route('crm.customers.show', ['customer' => '__ID__']) }}',
        customerUpdateUrlBase: '{{ route('crm.customers.update', ['customer' => '__ID__']) }}',
        chatUrlBase: '{{ route('conversations.index') }}',
        // The create-deal form re-opens itself after a failed submission —
        // the redirect-back-with-errors that validation does is a full page
        // load, and the modal's own x-show state has no way to survive that
        // on its own. Only its own fields' errors trigger this: an error
        // anywhere else on the page must not pop this modal open.
        openCreate: @js($errors->hasAny(['customer_id', 'new_customer_name', 'new_customer_phone', 'title', 'amount', 'deal_date'])),
        labels: {{ Js::from([
            'moveFailed' => __('crm.board.move_failed'),
            'saveFailed' => __('crm.board.save_failed'),
            'deleteFailed' => __('crm.board.delete_failed'),
            'saved' => __('crm.board.saved'),
            'deleted' => __('crm.board.deleted'),
            'deleteConfirm' => __('crm.board.delete_confirm'),
            'inStage' => __('crm.board.in_stage'),
            'stages' => __('crm.stages'),
            'stageBadge' => collect(\App\Models\Deal::allStages())->mapWithKeys(fn ($s) => [$s => \App\Models\Deal::badgeClass($s)]),
            'payment' => __('crm.payment_statuses'),
            'matchPrefix' => __('crm.board.phone_match_prefix'),
        ]) }},
    })" x-init="start()" @beforeunload.window="stop()">

    <div class="page-header">
        <div>
            <h1 class="page-title">{{ __('crm.board.heading') }}</h1>
            <p class="page-sub">{{ __('crm.board.subheading') }}</p>
            {{-- Page context — the thing a bare title never gave: how much is
                 actually here, under the filter currently applied. Recomputes
                 itself off the same `deals` array the table renders, so it is
                 never a second query that could disagree with the rows below it. --}}
        </div>
        <div class="page-actions">
            <button type="button" @click="adding = true" class="btn btn-primary btn-sm">{{ __('crm.board.add') }}</button>
        </div>
    </div>

    @include('crm.partials.subnav')

    <section class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label="{{ __('crm.board.overview_heading') }}">
        <article class="card card-pad-sm">
            <p class="text-xs font-medium text-muted dark:text-night-muted">{{ __('crm.board.metric_active') }}</p>
            <p class="mt-1 tnum text-2xl font-semibold" x-text="activeDealsCount()"></p>
            <p class="mt-1 text-xs text-muted dark:text-night-muted">{{ __('crm.board.metric_active_hint') }}</p>
        </article>
        <article class="card card-pad-sm">
            <p class="text-xs font-medium text-muted dark:text-night-muted">{{ __('crm.board.metric_paid') }}</p>
            <p class="mt-1 tnum text-2xl font-semibold" x-text="overview.counts.payment"></p>
            <p class="mt-1 text-xs text-muted dark:text-night-muted"><span x-text="formatMoney(overview.paid_value)"></span> · {{ __('crm.board.metric_paid_value') }}</p>
        </article>
        <article class="card card-pad-sm">
            <p class="text-xs font-medium text-muted dark:text-night-muted">{{ __('crm.board.metric_conversion') }}</p>
            <p class="mt-1 tnum text-2xl font-semibold" x-text="overview.conversion === null ? '—' : `${overview.conversion}%`"></p>
            <p class="mt-1 text-xs text-muted dark:text-night-muted">{{ __('crm.board.metric_conversion_hint') }}</p>
        </article>
        <article class="card card-pad-sm">
            <p class="text-xs font-medium text-muted dark:text-night-muted">{{ __('crm.board.metric_lost') }} <span class="tnum" x-text="overview.counts.cancelled"></span></p>
            <template x-if="overview.cancel_reasons.length">
                <div class="mt-2 flex flex-wrap gap-1.5">
                    <template x-for="reason in overview.cancel_reasons.slice(0, 2)" :key="reason.reason">
                        <span class="badge badge-neutral text-[11px]"><span x-text="reason.reason === 'unspecified' ? @js(__('crm.board.reason_unspecified')) : reason.reason"></span> · <span x-text="reason.total"></span></span>
                    </template>
                </div>
            </template>
            <p x-show="!overview.cancel_reasons.length" class="mt-1 text-xs text-muted dark:text-night-muted">{{ __('crm.board.metric_lost_hint') }}</p>
        </article>
    </section>

    @if (session('status'))
        <div class="card card-pad-sm mt-4 border-brand/30 bg-brand/5 text-sm">{{ session('status') }}</div>
    @endif

    {{-- One panel, not two floating pieces: the filter bar sits inside the
         same card as the rows it filters, divided by a hairline rather than a
         gap, so the toolbar reads as part of the list instead of a separate
         thing bolted on top of it. --}}
    <div class="card mt-5 overflow-hidden" data-crm-deals-list :aria-busy="loading">
        <div class="flex flex-wrap items-center gap-2 border-b border-hairline p-3 dark:border-white/10">
            <div class="tabs">
                <button type="button" @click="setStage('all')" class="tab" :class="filters.stage === 'all' && 'tab-active'">
                    {{ __('crm.board.filter_all') }}
                    <span class="badge badge-neutral tnum" x-text="totalDealsCount()"></span>
                </button>
                {{-- The set-apart stage stays a normal tab — a row's own badge
                     already keeps a cancelled deal visually distinct, so there
                     is no collapsed column left here to keep it out of. It is
                     placed last, and slightly muted when not the active tab,
                     as the one gentle reminder that this is where losses live. --}}
                <template x-for="stage in stages" :key="stage">
                    <button type="button" @click="setStage(stage)" class="tab"
                        :class="{
                            'tab-active': filters.stage === stage,
                            'text-muted/70 dark:text-night-muted/70': stage === cancelledStage && filters.stage !== stage,
                        }">
                        <span x-text="labels.stages[stage]"></span>
                        <span class="badge badge-neutral tnum" x-text="overview.counts[stage]"></span>
                    </button>
                </template>
                {{-- Not a stage — a customer with no deal at all. Rare (a deal
                     opens automatically the moment a real conversation
                     starts), but without somewhere to see them they would
                     simply vanish the day their deal gets deleted or their
                     history predates deals existing. A count only appears
                     once there is one; an empty bucket is not worth a
                     permanent "0". --}}
                <button type="button" @click="setStage(leadsBucket)" class="tab"
                    :class="{ 'tab-active': filters.stage === leadsBucket }">
                    {{ __('crm.board.leads_bucket') }}
                    <span x-show="leadsCount" x-cloak class="badge badge-neutral tnum" x-text="leadsCount"></span>
                </button>
            </div>

            <div class="ml-auto flex items-center gap-2">
                <div class="flex overflow-hidden rounded-full border border-black/10 text-xs dark:border-white/10">
                    <button type="button" @click="sortBy('date')"
                        class="px-3 py-1.5 font-medium transition-colors" :class="filters.sort === 'date' ? 'bg-brand/10 text-brand' : 'text-muted hover:bg-black/5 dark:text-night-muted dark:hover:bg-white/5'">
                        <span x-text="labels_sortDate"></span> <span x-show="filters.sort === 'date'" x-text="filters.dir === 'asc' ? '↑' : '↓'"></span>
                    </button>
                    <button type="button" @click="sortBy('amount')"
                        class="border-l border-black/10 px-3 py-1.5 font-medium transition-colors dark:border-white/10" :class="filters.sort === 'amount' ? 'bg-brand/10 text-brand' : 'text-muted hover:bg-black/5 dark:text-night-muted dark:hover:bg-white/5'">
                        <span x-text="labels_sortAmount"></span> <span x-show="filters.sort === 'amount'" x-text="filters.dir === 'asc' ? '↑' : '↓'"></span>
                    </button>
                </div>

                <div class="relative min-w-0 flex-1 sm:max-w-xs">
                    <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted dark:text-night-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="M20 20l-3.5-3.5"/></svg>
                    <input type="search" x-model="filters.q" @input.debounce.250ms="search()"
                        placeholder="{{ __('crm.board.search_placeholder') }}" data-i18n-ph="crm.board.search_placeholder"
                        aria-label="{{ __('crm.board.search_placeholder') }}" class="field w-full py-1.5 pl-9 pr-3 text-sm" />
                </div>
                <span x-show="loading" x-cloak role="status" class="shrink-0 text-xs text-muted dark:text-night-muted">{{ __('crm.board.loading') }}</span>
            </div>
        </div>

        {{-- The list. A real <table> in the shared .table-flat skin, not a
             from-scratch grid: it is what the rest of the product already
             uses for rows this shaped (Каталог, database preview), so a deal
             row reads like every other data row in the app rather than
             inventing its own idiom. Stage and payment share one column —
             two related signals read together faster than two columns the
             eye has to jump between — and the per-row stage picker and
             delete action stay out of the way until the row is actually
             hovered, so a resting list reads as calm data, not a control panel. --}}
        <div class="crm-deals-scroll">
            <table class="table-flat">
                <thead class="text-muted dark:text-night-muted/70">
                    <tr>
                        <th class="w-10 px-4 py-3 font-medium">#</th>
                        <th class="px-4 py-3 font-medium">{{ __('crm.board.col_client') }}</th>
                        <th class="px-4 py-3 font-medium">{{ __('crm.board.col_product') }}</th>
                        <th class="px-4 py-3 font-medium">{{ __('crm.board.col_amount') }}</th>
                        <th class="px-4 py-3 font-medium">{{ __('crm.board.col_stage') }}</th>
                        <th class="px-4 py-3 font-medium">{{ __('crm.board.col_activity') }}</th>
                        <th class="px-4 py-3 font-medium"><span class="sr-only">{{ __('crm.board.col_actions') }}</span></th>
                    </tr>
                </thead>
                <tbody x-show="filters.stage !== leadsBucket">
                    <template x-for="(deal, index) in visibleDeals()" :key="deal.id">
                        <tr class="group cursor-pointer border-t border-hairline transition-colors hover:bg-azure-50 dark:border-white/10 dark:hover:bg-night-raised"
                            @click="open(deal)">
                            <td class="tnum px-4 py-3.5 text-xs text-muted/70 dark:text-night-muted" x-text="(pagination.from || 1) + index"></td>
                            <td class="max-w-[12rem] px-4 py-3.5">
                                <div class="flex items-center gap-2">
                                    <span x-html="channelIcon(deal.channel)" class="shrink-0"></span>
                                    <span class="truncate font-medium" x-text="deal.customer_name"></span>
                                </div>
                            </td>
                            <td class="max-w-[14rem] truncate px-4 py-3.5 text-muted dark:text-night-muted" x-text="deal.title || '—'"></td>
                            <td class="tnum px-4 py-3.5 font-semibold" x-text="deal.amount || '—'"></td>
                            <td class="px-4 py-3.5">
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <span class="badge px-2.5 py-1 font-semibold" :class="labels.stageBadge[deal.stage]" x-text="labels.stages[deal.stage]"></span>
                                    {{-- Suppressed only in the one case it says
                                         nothing new: a deal already sitting in
                                         Оплата (which means paid now) whose
                                         payment status also reads paid. Every
                                         other combination — a partial payment
                                         mid-negotiation, say — is exactly the
                                         nuance this badge exists to surface,
                                         so it stays. --}}
                                    <span x-show="!(deal.stage === 'payment' && deal.payment_status === 'paid')" x-cloak
                                        class="badge" :class="{
                                        'badge-danger': deal.payment_status === 'unpaid',
                                        'badge-warning': deal.payment_status === 'partial',
                                        'badge-success': deal.payment_status === 'paid',
                                    }" x-text="labels.payment[deal.payment_status]"></span>
                                    <span x-show="deal.is_closed" x-cloak class="badge badge-success">{{ __('crm.board.closed_badge') }}</span>

                                </div>
                            </td>
                            <td class="px-4 py-3.5">
                                <p class="text-xs text-ink dark:text-night-ink" x-text="deal.last_activity || '—'"></p>
                                <p x-show="deal.in_stage" x-cloak class="text-[11px] text-muted dark:text-night-muted">
                                    <span x-text="deal.in_stage"></span> <span x-text="labels.inStage"></span>
                                </p>
                                <p x-show="deal.next_task" x-cloak class="mt-1 flex items-center gap-1 text-[11px]" :class="deal.next_task?.overdue ? 'font-semibold text-danger' : 'text-muted dark:text-night-muted'">
                                    <span aria-hidden="true">◷</span><span x-text="deal.next_task?.title"></span><span x-show="deal.next_task?.due_at" x-text="'· ' + deal.next_task?.due_at"></span>
                                </p>
                            </td>
                            <td class="px-4 py-3.5 text-right">
                                <button type="button" @click.stop="destroy(deal)"
                                    class="btn btn-ghost btn-sm opacity-0 transition-opacity focus:opacity-100 group-hover:opacity-100" aria-label="{{ __('crm.board.delete') }}">
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 7h12M9 7V5a1 1 0 011-1h4a1 1 0 011 1v2m2 0-1 13a2 2 0 01-2 2H8a2 2 0 01-2-2L5 7h14z"/></svg>
                                </button>
                            </td>
                        </tr>
                    </template>
                </tbody>

                {{-- A customer with no deal has no product, amount, stage or
                     payment status to show — the whole point of this row is
                     the one thing it CAN offer: opening a deal for them. --}}
                <tbody x-show="filters.stage === leadsBucket">
                    <template x-for="(lead, index) in visibleLeads()" :key="lead.id">
                        <tr class="border-t border-hairline dark:border-white/10">
                            <td class="tnum px-4 py-3.5 text-xs text-muted/70 dark:text-night-muted" x-text="(pagination.from || 1) + index"></td>
                            <td class="max-w-[12rem] px-4 py-3.5">
                                <a :href="customerUrl(lead.id)" class="flex items-center gap-2 hover:underline">
                                    <span x-html="channelIcon(lead.channel)" class="shrink-0"></span>
                                    <span class="truncate font-medium" x-text="lead.name"></span>
                                </a>
                            </td>
                            <td class="px-4 py-3.5 text-muted dark:text-night-muted" colspan="3">
                                <span class="badge badge-neutral">{{ __('crm.board.no_deal_yet') }}</span>
                            </td>
                            <td class="px-4 py-3.5 text-xs text-muted dark:text-night-muted" x-text="lead.last_activity || '—'"></td>
                            <td class="px-4 py-3.5 text-right">
                                <button type="button" @click="openCreateFor(lead.id, lead.name)" class="btn btn-secondary btn-sm">
                                    {{ __('crm.board.create_deal_for') }}
                                </button>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

            <div x-show="pagination.last_page > 1" x-cloak class="flex flex-col gap-3 border-t border-hairline px-4 py-3.5 text-sm dark:border-white/10 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-muted dark:text-night-muted">
                    {{ __('crm.board.page_summary_prefix') }} <span class="tnum font-medium text-ink dark:text-night-ink" x-text="`${pagination.from}–${pagination.to}`"></span>
                    {{ __('crm.board.page_summary_middle') }} <span class="tnum font-medium text-ink dark:text-night-ink" x-text="pagination.total"></span>
                </p>
                <nav class="flex items-center justify-end gap-1" aria-label="{{ __('crm.board.pagination_label') }}">
                    <button type="button" @click="goToPage(pagination.current_page - 1)" :disabled="pagination.current_page <= 1 || loading" class="btn btn-secondary btn-sm disabled:pointer-events-none disabled:opacity-40">{{ __('crm.board.previous_page') }}</button>
                    <template x-for="item in pageItems()" :key="item.key">
                        <span>
                            <span x-show="item.gap" class="px-2 text-muted dark:text-night-muted">…</span>
                            <button x-show="!item.gap" type="button" @click="goToPage(item.value)" :aria-current="pagination.current_page === item.value ? 'page' : false"
                                class="h-8 min-w-8 rounded-lg px-2 text-sm transition-colors"
                                :class="pagination.current_page === item.value ? 'bg-brand text-white shadow-sm' : 'text-muted hover:bg-black/5 dark:text-night-muted dark:hover:bg-white/5'" x-text="item.value"></button>
                        </span>
                    </template>
                    <button type="button" @click="goToPage(pagination.current_page + 1)" :disabled="pagination.current_page >= pagination.last_page || loading" class="btn btn-secondary btn-sm disabled:pointer-events-none disabled:opacity-40">{{ __('crm.board.next_page') }}</button>
                </nav>
            </div>

            <div x-show="filters.stage !== leadsBucket && !visibleDeals().length" x-cloak class="empty-state">
                <div class="empty-state-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 7h6m-6 4h6m-6 4h4M5 21V5a2 2 0 012-2h10a2 2 0 012 2v16l-3-2-2 2-2-2-2 2-2-2-3 2z"/></svg>
                </div>
                <p class="empty-state-title" x-text="filters.stage !== 'all' || filters.q ? @js(__('crm.board.empty_filtered_title')) : @js(__('crm.board.empty_title'))"></p>
                <p class="empty-state-text" x-text="filters.stage !== 'all' || filters.q ? @js(__('crm.board.empty_filtered_description')) : @js(__('crm.board.empty_description'))"></p>
            </div>

            <div x-show="filters.stage === leadsBucket && !visibleLeads().length" x-cloak class="empty-state">
                <div class="empty-state-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16 19a4 4 0 00-8 0M12 11a3 3 0 100-6 3 3 0 000 6z"/><circle cx="12" cy="12" r="9"/></svg>
                </div>
                <p class="empty-state-title">{{ __('crm.board.no_leads_title') }}</p>
                <p class="empty-state-text">{{ __('crm.board.no_leads_description') }}</p>
            </div>
        </div>
    </div>

    @include('crm.partials.deal-modal')
    @include('crm.partials.deal-create', ['initialCustomer' => $initialCustomer])
</div>

@push('scripts')
<script>
    /**
     * The deal list: the server returns only the requested page after
     * filtering and sorting. Search is debounced, and stage changes fetch
     * just the first page without navigating away. The URL mirrors the
     * current filters so the view stays shareable and reloadable.
     *
     * No drag-and-drop: the list has nothing to drop a row onto. A stage
     * change goes through changeStage()/save(), both of which call the exact
     * same server endpoints the old drag used to, so DealStageAutomation's
     * forward-only rule and the stage_set_by_owner_at guard do not change —
     * only how the owner triggers a move does.
     */
    function dealList({
        deals, leads, leadsBucket, stages, cancelledStage, filters, pagination, leadsCount, overview, pollUrlBase, listUrlBase,
        updateUrlBase, activityUrlBase, deleteUrlBase, customerUrlBase, customerUpdateUrlBase, chatUrlBase, openCreate, labels,
    }) {
        return {
            deals,
            leads,
            leadsBucket,
            stages,
            cancelledStage,
            filters,
            pagination,
            leadsCount,
            overview,
            labels,
            // Two-word i18n strings for the sort toggle, read once here so the
            // template above stays a plain x-text without another translation helper call.
            labels_sortDate: @js(__('crm.board.sort_date')),
            labels_sortAmount: @js(__('crm.board.sort_amount')),
            adding: openCreate,
            // A customer id (and name, for the dropdown option if they are
            // outside its own top-200) the "+ Сделка" button on a lead row
            // wants the create-deal modal pre-selected to. Read once, by the
            // nested dealCreateForm() scope, the moment it opens — see
            // openCreateFor().
            presetCustomerId: null,
            presetCustomerName: null,
            selected: null,
            activityItems: [],
            activityLoading: false,
            loading: false,
            editForm: { title: '', amount: '', payment_status: 'unpaid', note: '', stage: '', deal_date: '', cancelled_reason: '', closed: false },
            taskForm: { title: '', due_at: '' },
            taskErrors: {},
            taskSaving: false,
            editErrors: {},
            editSaving: false,
            _timer: null,
            _seq: 0,
            _lastRefreshAt: Date.now(),
            _lastFeedSignature: null,

            start() {
                this._lastFeedSignature = JSON.stringify({
                    deals: this.deals,
                    leads: this.leads,
                    pagination: this.pagination,
                    leadsCount: this.leadsCount,
                    summary: this.overview,
                });
                // Recheck quietly every 30 seconds. The rendered list already
                // came from the server, so there is no need for an immediate
                // duplicate request on first paint.
                this._timer = setInterval(() => {
                    if (document.visibilityState === 'visible' && !this.selected) this.refresh();
                }, 30000);

                document.addEventListener('visibilitychange', () => {
                    if (document.visibilityState === 'visible' && !this.selected) this.refresh();
                });
            },

            stop() {
                clearInterval(this._timer);
            },

            /** Already scoped, searched, sorted and paginated on the server. */
            visibleDeals() { return this.deals; },

            /** The "Без сделки" bucket, filtered by the same search box. */
            visibleLeads() { return this.leads; },

            pageItems() {
                const last = this.pagination.last_page;
                const current = this.pagination.current_page;
                const candidates = new Set([1, last, current - 1, current, current + 1].filter((n) => n >= 1 && n <= last));
                const values = [...candidates].sort((a, b) => a - b);
                const items = [];
                values.forEach((value, index) => {
                    if (index && value - values[index - 1] > 1) items.push({ key: `gap-${value}`, gap: true });
                    items.push({ key: value, value, gap: false });
                });
                return items;
            },

            goToPage(page) {
                if (page < 1 || page > this.pagination.last_page || page === this.pagination.current_page) return;
                this.pagination.current_page = page;
                this.loadPage();
            },

            query() {
                const params = new URLSearchParams();
                if (this.filters.stage && this.filters.stage !== 'all') params.set('stage', this.filters.stage);
                if (this.filters.q) params.set('q', this.filters.q);
                if (this.filters.sort && this.filters.sort !== 'date') params.set('sort', this.filters.sort);
                if (this.filters.dir && this.filters.dir !== 'desc') params.set('dir', this.filters.dir);
                if (this.pagination.current_page > 1) params.set('page', this.pagination.current_page);
                return params.toString();
            },

            /** Quietly refresh no more than once per 30 seconds, even on tab focus. */
            async refresh() {
                const now = Date.now();
                if (now - this._lastRefreshAt < 30000) return;
                this._lastRefreshAt = now;
                return this.loadPage(false, true);
            },

            async loadPage(syncAddress = true, silent = false) {
                const seq = ++this._seq;
                if (!silent) this.loading = true;
                const qs = this.query();
                if (syncAddress) window.history.replaceState({}, '', listUrlBase + (qs ? `?${qs}` : ''));

                try {
                    const res = await fetch(pollUrlBase + (qs ? `?${qs}` : ''), { headers: { Accept: 'application/json' } });
                    if (!res.ok || seq !== this._seq) return;

                    const data = await res.json();
                    if (seq === this._seq) {
                        // Keep the existing keyed rows in place when a poll
                        // found no data changes. This avoids a visible redraw
                        // and lets Alpine update only fields that really moved.
                        const signature = JSON.stringify(data);
                        if (signature !== this._lastFeedSignature) {
                            this.deals = data.deals;
                            this.leads = data.leads;
                            this.pagination = data.pagination;
                            this.leadsCount = data.leadsCount;
                            this.overview = data.summary;
                            this._lastFeedSignature = signature;
                        }
                    }
                } catch (e) {
                    // Keep the last good page visible; the next poll retries.
                } finally {
                    if (seq === this._seq) this.loading = false;
                }
            },

            /** Stage filters stay in place while results swap over JSON. */
            setStage(stage) {
                this.filters.stage = stage;
                this.pagination.current_page = 1;
                this.loadPage();
            },

            /** The search box. Instant, for the same reason. */
            search() {
                this.pagination.current_page = 1;
                this.loadPage();
            },

            /** A sort toggle. Instant, for the same reason. */
            sortBy(field) {
                this.pagination.current_page = 1;
                this.filters.dir = this.filters.sort === field && this.filters.dir === 'desc' ? 'asc' : 'desc';
                this.filters.sort = field;
                this.loadPage();
            },

            /**
             * The status quick-select in the deal panel. A partial save —
             * only `status` is sent — so it cannot clear the customer's name,
             * tags or note the way submitting the whole profile form would.
             */
            async changeCustomerStatus(deal, status) {
                if (!status || status === deal.customer_status) return;
                const previous = deal.customer_status;
                deal.customer_status = status;

                try {
                    const res = await fetch(customerUpdateUrlBase.replace('__ID__', deal.customer_id), {
                        method: 'PATCH',
                        headers: {
                            'Content-Type': 'application/json',
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': csrfToken(),
                        },
                        body: JSON.stringify({ status }),
                    });

                    if (!res.ok) throw new Error('status update failed');
                } catch (e) {
                    deal.customer_status = previous;
                    window.showToast?.(labels.saveFailed, 'error');
                }
            },

            /** Opens the detail/edit view for a row, seeded from what the list already has. */
            open(deal) {
                this.selected = deal;
                this.editErrors = {};
                this.editForm = {
                    title: deal.title || '',
                    amount: deal.amount_raw || '',
                    payment_status: deal.payment_status,
                    note: deal.note || '',
                    stage: deal.stage,
                    deal_date: deal.deal_date || '',
                    cancelled_reason: deal.cancelled_reason || '',
                    closed: !!deal.is_closed,
                };
                this.loadActivity(deal);
            },

            async loadActivity(deal) {
                this.activityLoading = true;
                this.activityItems = [];
                try {
                    const response = await fetch(activityUrlBase.replace('__ID__', deal.id), { headers: { Accept: 'application/json' } });
                    if (!response.ok) throw new Error('activity unavailable');
                    const data = await response.json();
                    if (this.selected?.id === deal.id) this.activityItems = data.items || [];
                } catch (e) {
                    this.activityItems = [];
                } finally {
                    this.activityLoading = false;
                }
            },

            async addNextTask() {
                if (!this.selected || !this.taskForm.title.trim()) return;
                this.taskSaving = true;
                this.taskErrors = {};
                try {
                    const response = await fetch(taskStoreUrl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken() },
                        body: JSON.stringify({
                            title: this.taskForm.title,
                            due_at: this.taskForm.due_at || null,
                            customer_id: this.selected.customer_id,
                            deal_id: this.selected.id,
                        }),
                    });
                    if (response.status === 422) {
                        const data = await response.json();
                        this.taskErrors = data.errors || {};
                        return;
                    }
                    if (!response.ok) throw new Error('task failed');
                    const data = await response.json();
                    this.taskForm = { title: '', due_at: '' };
                    const dealId = this.selected.id;
                    await this.loadPage(false);
                    this.selected = this.deals.find((deal) => deal.id === dealId) || this.selected;
                    await this.loadActivity(this.selected);
                    window.showToast?.(@js(__('crm.tasks.created')), 'success');
                } catch (e) {
                    window.showToast?.(labels.saveFailed, 'error');
                } finally {
                    this.taskSaving = false;
                }
            },

            close() {
                this.selected = null;
            },

            /** The "+ Сделка" button on a lead-without-a-deal row. */
            openCreateFor(customerId, customerName) {
                this.presetCustomerId = customerId;
                this.presetCustomerName = customerName;
                this.adding = true;
            },

            /**
             * Saves the edit form. A 422 comes back with field errors to show
             * inline — the same contract every other validated form in the
             * product uses, just read here instead of via a page reload,
             * since this form never leaves the page in the first place.
             */
            async save() {
                if (!this.selected) return;

                this.editSaving = true;
                this.editErrors = {};

                try {
                    const res = await fetch(updateUrlBase.replace('__ID__', this.selected.id), {
                        method: 'PATCH',
                        headers: {
                            'Content-Type': 'application/json',
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': csrfToken(),
                        },
                        body: JSON.stringify(this.editForm),
                    });

                    if (res.status === 422) {
                        const data = await res.json();
                        this.editErrors = data.errors || {};
                        return;
                    }

                    if (!res.ok) throw new Error('save failed');

                    const data = await res.json();
                    const idx = this.deals.findIndex((d) => d.id === this.selected.id);
                    // visibleDeals() drops the row out of view on its own if
                    // the save moved it out of the active stage tab.
                    if (idx !== -1) this.deals[idx] = data.deal;

                    window.showToast?.(labels.saved, 'success');
                    this.selected = null;
                    await this.loadPage(false);
                } catch (e) {
                    window.showToast?.(labels.saveFailed, 'error');
                } finally {
                    this.editSaving = false;
                }
            },

            /** Delete, from a row's own button or from the open detail view — same confirm, same call. */
            async destroy(deal) {
                if (!confirm(labels.deleteConfirm)) return;

                try {
                    const res = await fetch(deleteUrlBase.replace('__ID__', deal.id), {
                        method: 'DELETE',
                        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken() },
                    });

                    if (!res.ok) throw new Error('delete failed');

                    this.deals = this.deals.filter((d) => d.id !== deal.id);
                    if (this.selected?.id === deal.id) this.selected = null;
                    window.showToast?.(labels.deleted, 'success');
                    await this.loadPage(false);
                } catch (e) {
                    window.showToast?.(labels.deleteFailed, 'error');
                }
            },

            activeDealsCount() {
                return (this.overview.counts.new_lead || 0) + (this.overview.counts.haggling || 0);
            },

            totalDealsCount() {
                return Object.values(this.overview.counts).reduce((total, count) => total + Number(count || 0), 0);
            },

            formatMoney(value) {
                return new Intl.NumberFormat(document.documentElement.lang || 'ru', { maximumFractionDigits: 0 }).format(Math.round(Number(value) || 0));
            },

            customerUrl(id) {
                return customerUrlBase.replace('__ID__', id);
            },

            chatUrl(conversationId) {
                return `${chatUrlBase}?conversation=${conversationId}`;
            },

            channelIcon(type) {
                const mark = { greenapi: 'whatsapp', instagrapi: 'instagram' }[type] ?? type;

                const icons = {
                    telegram: '<svg viewBox="0 0 24 24" fill="#229ED9" class="h-3.5 w-3.5"><path d="M12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zm5.568 8.16l-1.61 7.59c-.12.539-.44.672-.888.418l-2.456-1.81-1.185 1.14c-.131.131-.24.24-.492.24l.175-2.49 4.53-4.093c.197-.175-.043-.273-.305-.098l-5.6 3.526-2.412-.755c-.524-.164-.535-.524.11-.776l9.42-3.63c.437-.16.82.098.677.777z"/></svg>',
                    whatsapp: '<svg viewBox="0 0 24 24" fill="#25D366" class="h-3.5 w-3.5"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51a12.8 12.8 0 00-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884a9.82 9.82 0 016.988 2.898 9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/></svg>',
                    instagram: '<svg viewBox="0 0 24 24" fill="#D62976" class="h-3.5 w-3.5"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/></svg>',
                };

                return icons[mark] ?? '';
            },
        };
    }

    function csrfToken() {
        return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    }

    /**
     * The "+ Новый клиент" fields inside the create-deal modal.
     *
     * Its own component rather than more properties on dealList(): the list
     * state above and this form share nothing, and Alpine's nested-scope
     * lookup still lets this read `labels` and `adding` from the parent
     * without either one needing to know the other exists.
     */
    function dealCreateForm({ lookupUrlBase, searchUrlBase, initial }) {
        return {
            customerId: initial.customerId,
            customerSearch: initial.customerName || '',
            selectedCustomer: initial.customerId && initial.customerName
                ? { id: Number(initial.customerId), name: initial.customerName }
                : null,
            customerResults: [],
            customerDropdownOpen: false,
            name: initial.name,
            phone: initial.phone,
            match: null,
            _checkSeq: 0,
            _searchSeq: 0,

            async searchCustomers() {
                const term = this.customerSearch.trim();
                if (this.selectedCustomer && term === this.selectedCustomer.name) {
                    this.customerDropdownOpen = true;
                    return;
                }

                this.customerId = '';
                this.selectedCustomer = null;
                this.customerDropdownOpen = true;
                const seq = ++this._searchSeq;

                try {
                    const response = await fetch(`${searchUrlBase}?q=${encodeURIComponent(term)}`, { headers: { Accept: 'application/json' } });
                    if (!response.ok || seq !== this._searchSeq) return;
                    const data = await response.json();
                    if (seq === this._searchSeq) this.customerResults = data.customers || [];
                } catch (e) {
                    this.customerResults = [];
                }
            },

            chooseCustomer(customer) {
                this.customerId = String(customer.id);
                this.customerSearch = customer.name;
                this.selectedCustomer = customer;
                this.customerDropdownOpen = false;
            },

            chooseNewCustomer() {
                this.customerId = '__new__';
                this.customerSearch = '';
                this.selectedCustomer = null;
                this.customerDropdownOpen = false;
            },

            /**
             * Does this number already belong to somebody? Debounced on the
             * input itself, not here, so a fast typist never fires one
             * request per keystroke.
             */
            async checkPhone() {
                const phone = this.phone.trim();
                this.match = null;

                if (!phone) return;

                // Out-of-order guard: a slow response to an earlier digit
                // must not overwrite what a later, faster one already found.
                const seq = ++this._checkSeq;

                try {
                    const res = await fetch(`${lookupUrlBase}?phone=${encodeURIComponent(phone)}`, {
                        headers: { Accept: 'application/json' },
                    });

                    if (!res.ok || seq !== this._checkSeq) return;

                    const data = await res.json();
                    if (seq === this._checkSeq) this.match = data.customer;
                } catch (e) {
                    // The server checks again independently on submit either
                    // way, so a dropped lookup here costs nothing but the hint.
                }
            },

            /** Selects the matched profile instead of risking a duplicate. */
            useMatch() {
                if (!this.match) return;

                this.chooseCustomer(this.match);
                this.name = '';
                this.phone = '';
                this.match = null;
            },

            /** Pre-selects a customer picked before this form opened. */
            applyPreset(customerId, customerName) {
                this.chooseCustomer({ id: customerId, name: customerName });
            },
        };
    }
</script>
@endpush
@endsection
