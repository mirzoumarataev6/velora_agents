@extends('layouts.dashboard')

@section('title', __('crm.customers.directory.title'))

@section('content')
<div class="crm-page" x-data="crmCustomerDirectory()" @resize.window="if (window.innerWidth >= 640) filtersOpen = true">
    <div class="page-header">
        <div>
            <h1 class="page-title">{{ __('crm.customers.directory.heading') }}</h1>
            <p class="page-sub">{{ __('crm.customers.directory.subheading') }}</p>
        </div>
    </div>

    @include('crm.partials.subnav')

    <section class="mt-5 grid grid-cols-2 gap-2 min-[380px]:grid-cols-3 sm:gap-3" aria-label="{{ __('crm.customers.directory.segment') }}">
        @foreach ([
            ['all', $counts['all']],
            ['buyers', $counts['buyers']],
            ['repeat', $counts['repeat']],
        ] as [$segment, $count])
            <button type="button" @click="setSegment('{{ $segment }}')"
                :aria-pressed="filters.segment === '{{ $segment }}'"
                class="card card-pad-sm group w-full cursor-pointer text-left transition-all hover:-translate-y-0.5 hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand {{ $loop->last ? 'col-span-2 min-[380px]:col-span-1' : '' }}"
                :class="filters.segment === '{{ $segment }}' ? 'border-brand/40 bg-brand/5 dark:bg-brand/5' : ''">
                <span class="flex items-center justify-between gap-3">
                    <span class="text-xs font-medium text-muted dark:text-night-muted">{{ __('crm.customers.directory.segments.'.$segment) }}</span>
                    <span aria-hidden="true" class="h-2 w-2 shrink-0 rounded-full bg-hairline transition-colors group-hover:bg-brand"
                        :class="filters.segment === '{{ $segment }}' ? 'bg-brand' : ''"></span>
                </span>
                <span class="tnum mt-1 block text-lg font-semibold sm:text-2xl">{{ number_format($count, 0, ',', ' ') }}</span>
            </button>
        @endforeach
    </section>

    <form method="GET" action="{{ route('crm.customers.index') }}" x-ref="form" @submit.prevent="search($refs.search.value)" class="card card-pad mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
        <div class="xl:col-span-2">
            <label for="customer-search" class="mb-1 block text-xs font-medium text-muted dark:text-night-muted">{{ __('crm.customers.directory.search') }}</label>
            <div class="relative">
                <input id="customer-search" name="q" value="{{ $filters['q'] ?? '' }}" x-ref="search"
                    @input.debounce.250ms="search($event.target.value)" @search="search($event.target.value)"
                    class="field w-full px-3 py-2 pr-9 text-sm" placeholder="{{ __('crm.customers.search_placeholder') }}"
                    autocomplete="off" />
                <span x-show="searching" x-cloak class="spinner absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2" aria-label="{{ __('crm.customers.directory.searching') }}"></span>
            </div>
        </div>
        <button type="button" @click="filtersOpen = !filtersOpen" :aria-expanded="filtersOpen"
            class="flex min-h-10 items-center justify-between rounded-lg border border-hairline px-3 text-sm font-medium text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand dark:border-night-raised dark:text-night-ink sm:hidden">
            {{ __('crm.customers.directory.more_filters') }} <span aria-hidden="true" x-text="filtersOpen ? '−' : '+'"></span>
        </button>
        <div x-show="filtersOpen" x-cloak class="grid gap-3 sm:contents">
        <div>
            <label for="customer-segment" class="mb-1 block text-xs font-medium text-muted dark:text-night-muted">{{ __('crm.customers.directory.segment') }}</label>
            <select id="customer-segment" name="segment" @change="search($refs.search.value)" class="field w-full px-3 py-2 text-sm">
                @foreach (['all', 'buyers', 'repeat', 'no_purchases'] as $segment)
                    <option value="{{ $segment }}" @selected(($filters['segment'] ?? 'all') === $segment)>{{ __('crm.customers.directory.segments.'.$segment) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="customer-status" class="mb-1 block text-xs font-medium text-muted dark:text-night-muted">{{ __('crm.customers.directory.status') }}</label>
            <select id="customer-status" name="status" @change="search($refs.search.value)" class="field w-full px-3 py-2 text-sm">
                <option value="">{{ __('crm.customers.all') }}</option>
                @foreach (\App\Models\Customer::STATUSES as $status)
                    <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ __('crm.statuses.'.$status) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="customer-origin" class="mb-1 block text-xs font-medium text-muted dark:text-night-muted">{{ __('crm.customers.directory.origin') }}</label>
            <select id="customer-origin" name="origin" @change="search($refs.search.value)" class="field w-full px-3 py-2 text-sm">
                <option value="">{{ __('crm.customers.all') }}</option>
                @foreach (\App\Models\Deal::SALES_ORIGINS as $origin)
                    <option value="{{ $origin }}" @selected(($filters['origin'] ?? '') === $origin)>{{ __('crm.sales_origins.'.$origin) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="customer-workspace" class="mb-1 block text-xs font-medium text-muted dark:text-night-muted">{{ __('crm.customers.directory.workspace') }}</label>
            <select id="customer-workspace" name="workspace" @change="search($refs.search.value)" class="field w-full px-3 py-2 text-sm">
                <option value="">{{ __('crm.workspaces.all') }}</option>
                @foreach ($workspaces as $workspace)
                    <option value="{{ $workspace->id }}" @selected((int) ($filters['workspace'] ?? 0) === $workspace->id)>{{ $workspace->displayName() }}</option>
                @endforeach
            </select>
        </div>
        </div>
    </form>

    <div x-ref="results" :class="searching && 'opacity-60'" :aria-busy="searching" class="mt-4 transition-opacity duration-150">
        @include('crm.customers.partials.results')
    </div>
</div>
@endsection

@push('scripts')
<script>
    function crmCustomerDirectory() {
        return {
            searching: false,
            filtersOpen: window.matchMedia('(min-width: 640px)').matches,
            sequence: 0,
            request: null,

            filters: { segment: @js($filters['segment'] ?? 'all') },

            setSegment(segment) {
                this.filters.segment = segment;
                this.$refs.form.elements.segment.value = segment;
                this.search(this.$refs.search.value);
            },

            async search(term) {
                const params = new URLSearchParams(new FormData(this.$refs.form));
                this.filters.segment = params.get('segment') || 'all';
                const query = (term ?? '').trim();
                if (query) params.set('q', query);
                else params.delete('q');
                params.delete('page');

                const url = new URL(this.$refs.form.action, window.location.origin);
                params.forEach((value, key) => {
                    if (value !== '') url.searchParams.set(key, value);
                });
                window.history.replaceState({}, '', url.pathname + url.search);

                const sequence = ++this.sequence;
                this.request?.abort();
                this.request = new AbortController();
                this.searching = true;

                try {
                    const response = await fetch(url, {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        signal: this.request.signal,
                    });
                    if (sequence !== this.sequence) return;
                    if (!response.ok) throw new Error('Search request failed');
                    this.$refs.results.innerHTML = await response.text();
                } catch (error) {
                    if (error.name !== 'AbortError') window.showToast?.(@js(__('crm.customers.directory.search_error')), 'error');
                } finally {
                    if (sequence === this.sequence) this.searching = false;
                }
            },
        };
    }
</script>
@endpush
