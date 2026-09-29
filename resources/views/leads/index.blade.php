@extends('layouts.dashboard')

@section('title', __('leads.index.title'))

@section('content')
{{-- The width cap lives in layouts/dashboard now, once, for every page. --}}
<div x-data="leadsBoard({
        updateUrlBase: '{{ route('leads.update', ['lead' => '__ID__']) }}',
        deleteUrlBase: '{{ route('leads.destroy', ['lead' => '__ID__']) }}',
        approveUrlBase: '{{ route('leads.price.approve', ['lead' => '__ID__']) }}',
        declineUrlBase: '{{ route('leads.price.decline', ['lead' => '__ID__']) }}',
        indexUrl: '{{ route('leads.index') }}',
        labels: {{ Js::from([
            'updated' => __('leads.index.updated'),
            'deleted' => __('leads.index.deleted'),
            'failed' => __('leads.index.action_failed'),
            'network' => __('leads.index.network_error'),
            'deleteConfirm' => __('leads.index.delete_confirm'),
            'approved' => __('leads.haggle.approved'),
            'declined' => __('leads.haggle.declined'),
            'declineConfirm' => __('leads.haggle.decline_confirm'),
        ]) }},
    })">
    <div class="page-header">
        <div>
            <h1 class="page-title">{{ __('leads.index.heading') }}</h1>
            <p class="page-sub">{{ __('leads.index.subheading') }}</p>
        </div>
        <div class="page-actions">

        @if ($agents->count() > 1)
            <select onchange="window.location.href = this.value"
                class="field px-4 py-2 text-sm">
                @php $tab = $activeView === 'orders' ? null : $activeView; @endphp
                <option value="{{ route('leads.index', array_filter(['view' => $tab, 'status' => $activeStatus, 'q' => $activeSearch])) }}" @selected(! $activeAgent)>{{ __('leads.index.all_agents') }}</option>
                @foreach ($agents as $agentOption)
                    <option value="{{ route('leads.index', array_filter(['view' => $tab, 'status' => $activeStatus, 'agent' => $agentOption->id, 'q' => $activeSearch])) }}" @selected((string) $activeAgent === (string) $agentOption->id)>{{ $agentOption->name }}</option>
                @endforeach
            </select>
        @endif
        </div>
    </div>

    {{-- Tabs first, then the status chips WITHIN the active tab.

         Orders and price requests are different work, not two flavours of the
         same list: one is a record to follow up when convenient, the other is a
         decision with a customer waiting in a live chat. Filed together the
         urgent kind sinks under the routine kind — which is what the badge on
         the second tab exists to stop. --}}
    <div class="tabs">
        @foreach (['orders', 'haggle'] as $viewKey)
            <a href="{{ route('leads.index', array_filter(['view' => $viewKey === 'orders' ? null : $viewKey, 'agent' => $activeAgent])) }}"
                class="tab {{ $activeView === $viewKey ? 'tab-active' : '' }}">
                {{ __("leads.views.$viewKey") }}
                @if ($viewKey === 'haggle' && $pendingHaggleCount > 0)
                    <span class="badge badge-warning tnum">{{ $pendingHaggleCount }}</span>
                @endif
            </a>
        @endforeach
    </div>

    {{-- Status filter + search. A plain GET form so the query lands in the URL
         and stays shareable, reloadable and back-button friendly — the same
         thing the inbox achieves with replaceState. --}}
    <div class="mt-4 flex flex-wrap items-center gap-2">
        {{-- data-lead-chip marks the links whose `q` has to be rewritten as the
             owner types; without it a chip clicked after a live search would
             navigate back to the term the server rendered with. --}}
        @foreach (array_merge([null], $statusOptions) as $value)
            <a href="{{ route('leads.index', array_filter(['view' => $activeView === 'orders' ? null : $activeView, 'status' => $value, 'agent' => $activeAgent, 'q' => $activeSearch])) }}"
                data-lead-chip
                class="rounded-full border px-4 py-1.5 text-sm font-medium transition-colors {{ $activeStatus === $value ? 'border-brand bg-brand/10 text-brand' : 'border-black/10 text-muted hover:bg-black/5 dark:border-white/10 dark:text-night-muted dark:hover:bg-white/5' }}">
                {{ __('leads.filters.'.($value ?? 'all')) }}
            </a>
        @endforeach

        {{-- Still a real GET form: without JS it submits and filters exactly as
             before. With JS the keystrokes filter live and the submit is
             redundant, so it is suppressed. --}}
        <form method="GET" action="{{ route('leads.index') }}" @submit.prevent="search($refs.search.value)"
            class="relative ml-auto min-w-0 flex-1 sm:max-w-xs">
            @if ($activeView !== 'orders')
                <input type="hidden" name="view" value="{{ $activeView }}" />
            @endif
            @if ($activeStatus)
                <input type="hidden" name="status" value="{{ $activeStatus }}" />
            @endif
            @if ($activeAgent)
                <input type="hidden" name="agent" value="{{ $activeAgent }}" />
            @endif
            <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted dark:text-night-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="M20 20l-3.5-3.5"/></svg>
            <input type="search" name="q" value="{{ $activeSearch }}" x-ref="search"
                @input.debounce.150ms="search($event.target.value)"
                @search="search($event.target.value)"
                placeholder="{{ __('leads.index.search_placeholder') }}" data-i18n-ph="leads.index.search_placeholder"
                aria-label="{{ __('leads.index.search_placeholder') }}"
                class="field w-full py-2 pl-9 pr-3 text-sm" />
            <span x-show="searching" x-cloak class="spinner absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2"></span>
        </form>
    </div>

    {{-- Swapped wholesale by the live search. Wrapped rather than replaced in
         place so the fade applies to the whole result set, not each card. --}}
    <div x-ref="results" :class="searching && 'opacity-60'" class="transition-opacity duration-150">
        @include('leads.partials.results')
    </div>
</div>

@push('scripts')
<script>
    function leadsBoard({ updateUrlBase, deleteUrlBase, approveUrlBase, declineUrlBase, indexUrl, labels }) {
        return {
            removed: [],
            searching: false,
            // Guards against an earlier, slower response overwriting a later
            // one — the classic out-of-order autocomplete bug.
            _seq: 0,
            _abort: null,

            /**
             * Live search.
             *
             * The server still runs the query, so results span every lead rather
             * than only the twenty currently rendered, and the markup comes back
             * from the same Blade partial the first paint used.
             */
            async search(term) {
                const q = (term ?? '').trim();
                const url = new URL(indexUrl, window.location.origin);

                // Carry whatever else is narrowing the list right now.
                const current = new URLSearchParams(window.location.search);
                ['view', 'status', 'agent'].forEach((key) => {
                    if (current.get(key)) url.searchParams.set(key, current.get(key));
                });
                if (q) url.searchParams.set('q', q);

                // Keep the address bar in step so the view stays shareable and
                // survives a reload, and re-point the chips at the new term.
                const shareable = url.pathname + (url.search || '');
                window.history.replaceState({}, '', shareable);
                this.syncChips(q);

                const seq = ++this._seq;
                this._abort?.abort();
                this._abort = new AbortController();

                const fragment = new URL(url);
                fragment.searchParams.set('fragment', '1');

                this.searching = true;
                try {
                    const res = await fetch(fragment, {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        signal: this._abort.signal,
                    });
                    if (!res.ok || seq !== this._seq) return;
                    // Alpine's mutation observer initialises the directives in
                    // the incoming cards, which sit inside this component's scope.
                    this.$refs.results.innerHTML = await res.text();
                } catch (e) {
                    if (e.name !== 'AbortError') window.showToast?.(labels.network, 'error');
                } finally {
                    if (seq === this._seq) this.searching = false;
                }
            },

            /** Status chips are rendered server-side; keep their `q` current. */
            syncChips(q) {
                document.querySelectorAll('[data-lead-chip]').forEach((chip) => {
                    const href = new URL(chip.getAttribute('href'), window.location.origin);
                    if (q) {
                        href.searchParams.set('q', q);
                    } else {
                        href.searchParams.delete('q');
                    }
                    chip.setAttribute('href', href.pathname + (href.search || ''));
                });
            },

            headers() {
                return {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                };
            },
            async updateStatus(id, status) {
                try {
                    const res = await fetch(updateUrlBase.replace('__ID__', id), {
                        method: 'PATCH',
                        headers: this.headers(),
                        body: JSON.stringify({ status }),
                    });
                    if (!res.ok) {
                        window.showToast?.(labels.failed, 'error');
                        return;
                    }
                    window.showToast?.(labels.updated, 'success');
                } catch (e) {
                    window.showToast?.(labels.network, 'error');
                }
            },
            /**
             * Approve or decline a haggle request. The optional number in the
             * box means different things either way — the price to grant, or
             * the counter to offer instead — which is why one input serves both
             * buttons.
             */
            async decide(url, body, successLabel, id) {
                try {
                    const res = await fetch(url.replace('__ID__', id), {
                        method: 'POST',
                        headers: this.headers(),
                        body: JSON.stringify(body),
                    });
                    if (!res.ok) {
                        window.showToast?.(labels.failed, 'error');
                        return;
                    }
                    window.showToast?.(successLabel, 'success');
                    // The card's actions are gone now and its state has moved,
                    // so the row is re-rendered from the server rather than
                    // patched in place.
                    this.search(this.$refs.search?.value ?? '');
                } catch (e) {
                    window.showToast?.(labels.network, 'error');
                }
            },
            approvePrice(id, price) {
                const value = parseFloat(price);
                this.decide(approveUrlBase, Number.isFinite(value) && value > 0 ? { price: value } : {}, labels.approved, id);
            },
            declinePrice(id, counter) {
                const value = parseFloat(counter);
                if (!Number.isFinite(value) && !confirm(labels.declineConfirm)) return;
                this.decide(declineUrlBase, Number.isFinite(value) && value > 0 ? { counter_price: value } : {}, labels.declined, id);
            },
            async remove(id) {
                if (!confirm(labels.deleteConfirm)) return;
                try {
                    const res = await fetch(deleteUrlBase.replace('__ID__', id), {
                        method: 'DELETE',
                        headers: this.headers(),
                    });
                    if (!res.ok) {
                        window.showToast?.(labels.failed, 'error');
                        return;
                    }
                    this.removed.push(id);
                    window.showToast?.(labels.deleted, 'success');
                } catch (e) {
                    window.showToast?.(labels.network, 'error');
                }
            },
        };
    }
</script>
@endpush
@endsection
