{{-- Visible scope is part of the answer: a reader must see that a KPI says
     "Душанбе, март" before interpreting its value. Every selection is saved
     through SpecValidator, then cards are fetched in place. --}}
@if ($rendered->isNotEmpty())
<div class="card mt-5 rounded-2xl px-5 py-4">
    {{-- On a phone, a dense filter form must not displace the actual charts
         from the first screen. Keep its state visible and all controls one
         deliberate tap away; desktop retains the full form. --}}
    <button type="button" data-qa="filter-toggle" class="flex min-h-10 w-full items-center justify-between text-left text-sm font-semibold md:hidden"
        @click="filtersOpen = !filtersOpen" :aria-expanded="filtersOpen">
        <span>{{ __('bi.dashboards.filters') }} <span x-show="globalFrom || globalTo || globalValue" x-cloak class="badge badge-info ml-1" x-text="[globalFrom, globalTo, globalValue].filter(Boolean).length"></span></span>
        <span aria-hidden="true" x-text="filtersOpen ? '−' : '+'"></span>
    </button>
    <div x-show="filtersOpen" x-cloak class="mt-3 md:!block md:mt-0">
    <div class="flex flex-wrap items-end gap-3">
        <div class="mr-2 hidden self-center text-sm font-semibold md:block">{{ __('bi.dashboards.filters') }}</div>
        <template x-if="timeColumn">
            <div class="flex flex-wrap items-end gap-2">
                <label class="text-xs text-muted dark:text-night-muted">{{ __('bi.analysis.date_from') }}
                    <span class="relative mt-1 block">
                        <input type="text" inputmode="numeric" autocomplete="off" class="field block !w-40 !py-1.5 pr-8 text-sm" placeholder="{{ __('bi.dashboards.date_format_hint') }}" aria-label="{{ __('bi.analysis.date_from') }} · {{ __('bi.dashboards.date_format_hint') }}" x-model="globalFromDisplay">
                        <input type="date" class="pointer-events-none absolute right-0 top-0 h-1 w-1 opacity-0" x-ref="fromPicker" @change="globalFromDisplay = formatFilterDate($event.target.value)">
                        <button type="button" class="absolute inset-y-0 right-0 flex w-8 items-center justify-center text-muted hover:text-brand-strong dark:text-night-muted dark:hover:text-brand-light" aria-label="{{ __('bi.analysis.date_from') }} — {{ __('bi.dashboards.open_calendar') }}" @click="if ($refs.fromPicker.showPicker) $refs.fromPicker.showPicker(); else $refs.fromPicker.focus()"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4m10-4v4M3 10h18"/></svg></button>
                    </span>
                </label>
                <label class="text-xs text-muted dark:text-night-muted">{{ __('bi.analysis.date_to') }}
                    <span class="relative mt-1 block">
                        <input type="text" inputmode="numeric" autocomplete="off" class="field block !w-40 !py-1.5 pr-8 text-sm" placeholder="{{ __('bi.dashboards.date_format_hint') }}" aria-label="{{ __('bi.analysis.date_to') }} · {{ __('bi.dashboards.date_format_hint') }}" x-model="globalToDisplay">
                        <input type="date" class="pointer-events-none absolute right-0 top-0 h-1 w-1 opacity-0" x-ref="toPicker" @change="globalToDisplay = formatFilterDate($event.target.value)">
                        <button type="button" class="absolute inset-y-0 right-0 flex w-8 items-center justify-center text-muted hover:text-brand-strong dark:text-night-muted dark:hover:text-brand-light" aria-label="{{ __('bi.analysis.date_to') }} — {{ __('bi.dashboards.open_calendar') }}" @click="if ($refs.toPicker.showPicker) $refs.toPicker.showPicker(); else $refs.toPicker.focus()"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4m10-4v4M3 10h18"/></svg></button>
                    </span>
                </label>
            </div>
        </template>
        <template x-if="categoryColumns.length">
            <div class="flex flex-wrap items-end gap-2">
                <label class="text-xs text-muted dark:text-night-muted">{{ __('bi.analysis.category') }}
                    <select class="field mt-1 block !w-40 !py-1.5 text-sm" x-model="globalCategory">
                        <template x-for="column in categoryColumns" :key="column.name"><option :value="column.name" x-text="column.label"></option></template>
                    </select>
                </label>
                <label class="text-xs text-muted dark:text-night-muted">{{ __('bi.analysis.value') }}<input type="text" class="field mt-1 block !w-40 !py-1.5 text-sm" x-model="globalValue"></label>
            </div>
        </template>
        <button type="button" class="btn btn-primary btn-sm min-h-10" @click="saveGlobalFilters()" :disabled="savingFilters">{{ __('bi.analysis.apply') }}</button>
        <button type="button" class="btn btn-ghost btn-sm min-h-10" @click="clearGlobalFilters()">{{ __('bi.dashboards.filter_clear') }}</button>
    </div>
    <div x-show="categoryValues.length" class="mt-3 flex flex-wrap gap-2">
        <template x-for="value in categoryValues" :key="value"><button type="button" class="min-h-10 rounded-full border border-hairline px-3 py-1 text-xs transition hover:border-brand dark:border-night-raised" :class="globalValue === value && 'border-brand bg-brand-circle dark:bg-night-raised'" x-text="value" @click="globalValue = globalValue === value ? '' : value; saveGlobalFilters()"></button></template>
    </div>
    <p x-show="filterError" x-text="filterError" class="mt-2 text-xs text-danger" role="alert"></p>
    </div>
</div>
@endif
