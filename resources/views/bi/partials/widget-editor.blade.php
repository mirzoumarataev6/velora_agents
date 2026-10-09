{{-- THE WIDGET EDITOR, as a right-hand drawer rather than a modal.

     A drawer, because the thing being edited is behind it: an owner changing a
     chart type wants to see the board they are changing. A centred modal covers
     exactly the content the decision is about.

     Saves use the same server validation as ordinary forms, then replace only
     the affected card. A failed save leaves the existing answer untouched. --}}
<div x-show="editorOpen" x-cloak class="fixed inset-0 z-40" role="dialog" aria-modal="true">
    <div class="absolute inset-0 bg-ink/30 backdrop-blur-[2px]" @click="closeEditor()"></div>

    <div class="absolute inset-y-0 right-0 flex w-full max-w-md flex-col bg-surface shadow-2xl dark:bg-night-card"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
        @keydown.escape.window="closeEditor()">

        <form :action="draft.id ? widgetUpdateUrl(draft.id) : widgetStoreUrl" method="POST" @submit.prevent="saveWidget($event)"
            class="flex h-full flex-col">
            @csrf
            <template x-if="draft.id"><input type="hidden" name="_method" value="PATCH" /></template>

            <div class="flex items-center justify-between border-b border-hairline px-5 py-3 dark:border-night-raised">
                <h2 class="text-sm font-medium text-ink dark:text-night-ink"
                    x-text="draft.id ? @js(__('bi.widgets.edit')) : @js(__('bi.widgets.add'))"></h2>
                <button type="button" class="flex h-10 w-10 items-center justify-center rounded-lg text-xl text-muted hover:bg-sunk focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand dark:hover:bg-night-raised" @click="closeEditor()" aria-label="{{ __('bi.widgets.cancel') }}">×</button>
            </div>

            <div class="flex-1 space-y-4 overflow-y-auto px-5 py-4">
                <p x-show="editorError" x-cloak x-text="editorError" class="rounded-lg bg-danger/10 px-3 py-2 text-sm text-danger" role="alert"></p>
                <div>
                    <label class="block text-sm font-medium text-ink dark:text-night-ink">{{ __('bi.widgets.title') }}</label>
                    <input type="text" name="title" required maxlength="120" class="field mt-1 w-full"
                        x-model="draft.title" placeholder="{{ __('bi.widgets.title_placeholder') }}" />
                </div>

                <div>
                    <label class="block text-sm font-medium text-ink dark:text-night-ink">{{ __('bi.widgets.dataset') }}</label>
                    <select name="bi_dataset_id" class="field mt-1 w-full" x-model.number="draft.dataset_id"
                        @change="onDatasetChange()">
                        <template x-for="dataset in datasets" :key="dataset.id">
                            <option :value="dataset.id" x-text="dataset.name"></option>
                        </template>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-ink dark:text-night-ink">{{ __('bi.widgets.type') }}</label>
                    {{-- A grid of buttons rather than a select: the choice is
                         visual, there are seven of them, and the hint under
                         each is what stops an owner picking a donut for a time
                         series. --}}
                    <div class="mt-1 grid grid-cols-2 gap-2">
                        @foreach ($widgetTypes as $type)
                            <label class="flex min-h-16 cursor-pointer items-start gap-2 rounded-xl border border-hairline p-2.5 text-sm transition
                                          hover:border-brand dark:border-night-raised"
                                :class="draft.type === '{{ $type->value }}' && 'border-brand bg-brand-circle dark:bg-night-raised'">
                                <input type="radio" name="type" value="{{ $type->value }}" class="mt-0.5"
                                    x-model="draft.type" @change="onTypeChange()" :disabled="!canUseType('{{ $type->value }}')" />
                                <span>
                                    <span class="block font-medium text-ink dark:text-night-ink">{{ __('bi.widgets.types.'.$type->value) }}</span>
                                    <span class="block text-xs text-muted dark:text-night-muted">{{ __('bi.widgets.type_hints.'.$type->value) }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                </div>

                {{-- SPLIT BY. Hidden entirely for a KPI, which takes no
                     dimension — showing a disabled control the owner cannot use
                     explains less than not showing it. --}}
                <div x-show="maxDimensions > 0">
                    <label class="block text-sm font-medium text-ink dark:text-night-ink">{{ __('bi.widgets.dimension') }}</label>

                    <template x-for="(dim, i) in draft.dimensions" :key="i">
                        <div class="mt-1 flex items-center gap-2">
                            <select class="field !py-1.5 text-sm" :name="`spec[dimensions][${i}][column]`" x-model="dim.column">
                                <template x-for="column in dimensionColumns" :key="column.name">
                                    <option :value="column.name" x-text="column.label"></option>
                                </template>
                            </select>

                            {{-- The grain selector appears only for a date
                                 column, because it is meaningless on anything
                                 else and the query layer refuses it outright. --}}
                            <select class="field !w-32 !py-1.5 text-sm" :name="`spec[dimensions][${i}][grain]`"
                                x-model="dim.grain" x-show="isTemporal(dim.column)">
                                @foreach (\App\Services\Bi\Query\TimeGrain::cases() as $grain)
                                    <option value="{{ $grain->value }}">{{ __('bi.grains.'.$grain->value) }}</option>
                                @endforeach
                            </select>

                            <button type="button" class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg text-muted hover:bg-sunk focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand dark:hover:bg-night-raised"
                                aria-label="{{ __('bi.dashboards.filter_clear') }}"
                                x-show="draft.dimensions.length > minDimensions"
                                @click="draft.dimensions.splice(i, 1)">×</button>
                        </div>
                    </template>

                    <button type="button" class="btn-ghost mt-1 text-xs"
                        x-show="draft.dimensions.length < maxDimensions"
                        @click="addDimension()">+ {{ __('bi.widgets.dimension') }}</button>
                </div>

                <div>
                    <label class="block text-sm font-medium text-ink dark:text-night-ink">{{ __('bi.widgets.measure') }}</label>

                    <template x-for="(measure, i) in draft.measures" :key="i">
                        <div class="mt-1 flex items-center gap-2">
                            <select class="field !w-36 !py-1.5 text-sm" :name="`spec[measures][${i}][aggregate]`"
                                x-model="measure.aggregate" @change="onMeasureChange(measure)">
                                @foreach (\App\Services\Bi\Query\Aggregate::cases() as $aggregate)
                                    <option value="{{ $aggregate->value }}" :disabled="!hasNumeric && {{ in_array($aggregate->value, ['sum', 'avg']) ? 'true' : 'false' }}">{{ __('bi.aggregates.'.$aggregate->value) }}</option>
                                @endforeach
                            </select>

                            {{-- COUNT takes no column, and the select
                                 disappears rather than being disabled: a
                                 greyed-out dropdown next to "Rows" invites the
                                 question "which column am I counting?", whose
                                 answer is "none, that is the point". --}}
                            <select class="field !py-1.5 text-sm" :name="`spec[measures][${i}][column]`"
                                x-model="measure.column" x-show="measure.aggregate !== 'count'">
                                <template x-for="column in measureColumns(measure.aggregate)" :key="column.name">
                                    <option :value="column.name" x-text="column.label"></option>
                                </template>
                            </select>

                            <button type="button" class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg text-muted hover:bg-sunk focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand dark:hover:bg-night-raised"
                                aria-label="{{ __('bi.dashboards.filter_clear') }}"
                                x-show="draft.measures.length > minMeasures"
                                @click="draft.measures.splice(i, 1)">×</button>
                        </div>
                    </template>

                    <button type="button" class="btn-ghost mt-1 text-xs"
                        x-show="draft.measures.length < maxMeasures"
                        @click="addMeasure()">+ {{ __('bi.widgets.measure') }}</button>
                </div>

                <div x-show="draft.type !== 'kpi'">
                    <label class="block text-sm font-medium text-ink dark:text-night-ink">{{ __('bi.widgets.limit') }}</label>
                    <input type="number" name="spec[limit]" min="1" max="{{ \App\Services\Bi\Query\QuerySpec::maxRows() }}"
                        class="field mt-1 !w-28" x-model.number="draft.limit" />
                </div>

                <div class="border-t border-hairline pt-4 dark:border-night-raised">
                    <label class="block text-sm font-medium text-ink dark:text-night-ink">{{ __('bi.dashboards.filters') }}</label>
                    <template x-for="(filter, i) in draft.filters" :key="i">
                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            <select class="field !w-36 !py-1.5 text-sm" :name="`filters[${i}][column]`" x-model="filter.column">
                                <template x-for="column in columns" :key="column.name"><option :value="column.name" x-text="column.label"></option></template>
                            </select>
                            <select class="field !w-28 !py-1.5 text-sm" :name="`filters[${i}][operator]`" x-model="filter.operator">
                                <option value="eq">=</option><option value="ne">≠</option><option value="contains">{{ __('bi.dashboards.filter_contains') }}</option>
                                <option value="gte" :disabled="!ordered(filter.column)">≥</option><option value="lte" :disabled="!ordered(filter.column)">≤</option>
                            </select>
                            <input class="field !w-32 !py-1.5 text-sm" :name="`filters[${i}][value]`" x-model="filter.value" required>
                            <button type="button" class="btn-ghost text-xs" @click="draft.filters.splice(i,1)" aria-label="{{ __('bi.dashboards.filter_clear') }}">×</button>
                        </div>
                    </template>
                    <button type="button" class="btn-ghost mt-2 text-xs" @click="addFilter()">+ {{ __('bi.dashboards.filter_add') }}</button>
                </div>
            </div>

            {{-- A live configuration summary, not a fabricated data chart. The
                 actual query runs on save; this tells the owner what it will ask. --}}
            <div class="border-t border-hairline bg-sunk/60 px-5 py-3 text-xs dark:border-night-raised dark:bg-night-raised/40">
                <p class="font-semibold text-ink dark:text-night-ink">{{ __('bi.widgets.configuration_preview') }}</p>
                <p class="mt-1 text-muted dark:text-night-muted">
                    <span x-text="widgetTypeNames[draft.type] || draft.type"></span>
                    <span aria-hidden="true"> · </span>
                    <span x-text="datasets.find(dataset => dataset.id === draft.dataset_id)?.name || '—'"></span>
                </p>
                <p class="mt-0.5 text-muted dark:text-night-muted" x-show="draft.dimensions.length">
                    {{ __('bi.widgets.dimension') }}:
                    <span x-text="draft.dimensions.map(dim => columns.find(column => column.name === dim.column)?.label || dim.column).join(', ')"></span>
                </p>
                <p class="mt-0.5 text-muted dark:text-night-muted" x-show="draft.measures.length">
                    {{ __('bi.widgets.measure') }}:
                    <span x-text="draft.measures.map(measure => aggregateNames[measure.aggregate] + (measure.aggregate === 'count' ? '' : ' · ' + (columns.find(column => column.name === measure.column)?.label || measure.column))).join(', ')"></span>
                </p>
            </div>

            <div class="flex items-center justify-end gap-2 border-t border-hairline px-5 py-3 dark:border-night-raised">
                <button type="button" class="btn-ghost text-sm" @click="closeEditor()">{{ __('bi.widgets.cancel') }}</button>
                <button type="submit" class="btn btn-primary text-sm" :disabled="savingEditor">{{ __('bi.widgets.save') }}</button>
            </div>
        </form>
    </div>
</div>
