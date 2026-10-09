@props(['widget', 'result'])

@php
    $searchable = $widget->dataset?->columns?->filter(fn ($column) => $column->type->value === 'text' && collect($widget->spec['dimensions'] ?? [])->contains('column', $column->physical_name))->values() ?? collect();
@endphp
<div class="flex h-full min-h-0 flex-col" x-data="biTable({
    url: @js(route('bi.widgets.data', [$widget->dashboard, $widget])),
    rows: {{ Js::from($result->rows) }}, columns: {{ Js::from($result->columns) }},
    hasMore: {{ $result->hasMore ? 'true' : 'false' }}, offset: {{ $result->offset }}, pageSize: {{ $result->pageSize }},
    searchColumn: @js($searchable->first()?->physical_name),
})">
    @if ($searchable->isNotEmpty())
        <div class="mb-3 flex gap-2">
            <select class="field !w-28 !py-1 text-xs" x-model="searchColumn" aria-label="{{ __('bi.analysis.category') }}">
                @foreach ($searchable as $column)<option value="{{ $column->physical_name }}">{{ $column->displayName() }}</option>@endforeach
            </select>
            <input type="search" class="field min-w-0 flex-1 !py-1 text-xs" x-model="search" @input.debounce.350ms="load(0)" placeholder="{{ __('bi.analysis.search') }}" aria-label="{{ __('bi.analysis.search') }}">
        </div>
    @endif
    <div class="min-h-0 flex-1 overflow-auto">
        <table class="w-full min-w-max text-sm">
            <thead><tr class="border-b border-hairline dark:border-night-raised">
                @foreach ($result->columns as $column)
                    <th scope="col" class="px-2 py-2 text-left text-xs font-medium text-muted dark:text-night-muted">
                        <button type="button" class="flex items-center gap-1 hover:text-brand" @click="sortBy('{{ $column['alias'] }}')">{{ $column['label'] ?: __('bi.aggregates.'.$column['aggregate']) }} <span x-show="sort === '{{ $column['alias'] }}'" x-text="direction === 'asc' ? '↑' : '↓'"></span></button>
                    </th>
                @endforeach
            </tr></thead>
            <tbody>
                <template x-for="(row, index) in rows" :key="index"><tr class="border-b border-hairline/60 last:border-0 dark:border-night-raised/60">
                    <template x-for="column in columns" :key="column.alias"><td class="px-2 py-2 text-ink-soft dark:text-night-ink" :class="column.kind === 'measure' && 'text-right tabular-nums'" x-text="format(row[column.alias], column)"></td></template>
                </tr></template>
            </tbody>
        </table>
        <p x-show="!rows.length && !loading" class="py-4 text-center text-xs text-muted dark:text-night-muted">{{ __('bi.widgets.no_data') }}</p>
        <p x-show="loading" class="py-4 text-center text-xs text-muted dark:text-night-muted">{{ __('bi.analysis.processing') }}…</p>
        <p x-show="error" x-text="error" class="py-2 text-xs text-danger"></p>
    </div>
    <div class="mt-2 flex items-center justify-between border-t border-hairline pt-2 text-xs dark:border-night-raised">
        <span class="text-muted dark:text-night-muted" x-text="`${offset + 1}–${offset + rows.length}`"></span>
        <div class="flex gap-1"><button type="button" class="btn btn-ghost btn-sm" :disabled="offset === 0 || loading" @click="load(Math.max(0, offset - pageSize))">← {{ __('bi.analysis.previous') }}</button><button type="button" class="btn btn-ghost btn-sm" :disabled="!hasMore || loading" @click="load(offset + pageSize)">{{ __('bi.analysis.next') }} →</button></div>
    </div>
</div>
