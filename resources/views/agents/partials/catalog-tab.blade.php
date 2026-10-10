@php
    /**
     * The Каталог tab. Three states, one component:
     *   idle    — no pending upload: intro + drop zone, plus the current
     *             catalog's status and preview when one exists.
     *   mapping — a file has been analyzed: its columns, sample values and the
     *             suggested mapping, awaiting the owner's confirmation.
     *   done    — folded back into idle; the status card re-renders from the
     *             import response.
     *
     * Nothing is written until the owner confirms the mapping, so leaving the
     * tab mid-flow costs them the upload and nothing else.
     */
    $fieldLabels = __('agents.catalog.fields');
    $maxMb = round(config('agent.dataset.max_file_size_kb') / 1024);
@endphp

<div x-data="catalogTab({
        productsUrl: '{{ route('agents.catalog.products', $agent) }}',
        analyzeUrl: '{{ route('agents.catalog.analyze', $agent) }}',
        importUrl: '{{ route('agents.catalog.import', $agent) }}',
        cancelUrl: '{{ route('agents.catalog.cancel', $agent) }}',
        destroyUrl: '{{ route('agents.catalog.destroy', $agent) }}',
        productImagesUrlBase: '{{ route('agents.catalog.products.images.store', [$agent, '__ID__']) }}',
        productImageMoveUpUrlBase: '{{ route('agents.catalog.products.images.move_up', [$agent, '__PRODUCT__', '__IMAGE__']) }}',
        productImageDeleteUrlBase: '{{ route('agents.catalog.products.images.destroy', [$agent, '__PRODUCT__', '__IMAGE__']) }}',
        initial: {{ Js::from($catalog) }},
    })" class="space-y-6">

    {{-- The agent is answering from a live connection instead. The catalog below
         is still here and still editable, but nothing in it reaches customers —
         saying so beats letting an owner tune prices that are never quoted. --}}
    @if ($agent->usesLiveDatabase())
        <p class="rounded-2xl bg-warning/10 px-4 py-3 text-xs leading-relaxed text-warning dark:text-warning-light">
            {{ __('agents.catalog.live_source_notice') }}
        </p>
    @endif

    {{-- Intro + upload zone (hidden while mapping, so the owner has one job at a time) --}}
    <div x-show="!pending" x-cloak>
        <div class="rounded-2xl p-5">
            <h3 class="text-sm font-semibold">{{ __('agents.catalog.intro_title') }}</h3>
            <p class="mt-1 text-xs leading-relaxed text-muted dark:text-night-muted">{{ __('agents.catalog.intro_description') }}</p>
        </div>

        <div
            @dragover.prevent="dragging = true"
            @dragleave.prevent="dragging = false"
            @drop.prevent="dragging = false; handleFile($event.dataTransfer.files[0])"
            :class="dragging ? 'border-brand bg-azure-50' : 'border-black/15 dark:border-white/15'"
            class="mt-4 flex flex-col items-center justify-center gap-2 rounded-2xl border-2 border-dashed px-6 py-10 text-center transition-colors">
            <svg x-show="!uploading" class="h-8 w-8 text-muted dark:text-night-muted/70" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 7h18M3 12h18M3 17h18M8 3v18"/></svg>
            <span x-show="uploading" class="spinner h-8 w-8"></span>
            <p class="text-sm font-medium" x-show="!uploading">
                {{ __('agents.catalog.drop_text') }}
                <button type="button" @click="$refs.fileInput.click()" class="text-brand-strong hover:underline">{{ __('agents.catalog.drop_browse') }}</button>
            </p>
            <p class="text-sm font-medium" x-show="uploading" x-cloak>{{ __('agents.catalog.uploading') }}</p>
            <p class="text-xs text-muted dark:text-night-muted">{{ __('agents.catalog.file_hint', ['size' => $maxMb]) }}</p>
            <input type="file" x-ref="fileInput" accept=".xlsx,.xls,.csv" class="hidden"
                @change="handleFile($event.target.files[0]); $event.target.value = ''" />
        </div>
    </div>

    {{-- Column mapping — the confirmation step --}}
    <div x-show="pending" x-cloak x-transition.opacity.duration.200ms class="rounded-2xl">
        <div class="border-b border-hairline px-5 py-4 dark:border-white/10">
            <h3 class="text-sm font-semibold">{{ __('agents.catalog.mapping_title') }}</h3>
            <p class="mt-1 text-xs leading-relaxed text-muted dark:text-night-muted">{{ __('agents.catalog.mapping_description') }}</p>
            <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted dark:text-night-muted">
                <span class="font-medium" x-text="pending?.filename"></span>
                <span x-text="rowsFoundLabel.replace(':count', pending?.row_count ?? 0)"></span>
            </div>
            <p x-show="pending?.mapping_reused" x-cloak class="mt-2 text-xs text-success dark:text-success-light">{{ __('agents.catalog.mapping_reused') }}</p>
            <p x-show="pending?.truncated" x-cloak class="mt-2 text-xs text-warning dark:text-warning-light"
                x-text="truncatedLabel.replace(':count', pending?.row_count ?? 0)"></p>
            <p x-show="error" x-cloak x-text="error" class="mt-2 text-xs text-danger"></p>
        </div>

        <div class="overflow-x-auto">
            <table class="table-flat">
                <thead>
                    <tr>
                        <th class="px-5 py-3 font-medium">{{ __('agents.catalog.mapping_col_file') }}</th>
                        <th class="px-5 py-3 font-medium">{{ __('agents.catalog.mapping_col_sample') }}</th>
                        <th class="px-5 py-3 font-medium">{{ __('agents.catalog.mapping_col_field') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="(column, index) in (pending?.columns ?? [])" :key="index">
                        <tr class="border-t border-hairline dark:border-white/10">
                            <td class="max-w-[14rem] truncate px-5 py-3 font-medium" x-text="column"></td>
                            <td class="max-w-xs truncate px-5 py-3 text-xs text-muted dark:text-night-muted" x-text="sampleFor(index)"></td>
                            <td class="px-5 py-3">
                                <select :value="fieldFor(index)" @change="assign(index, $event.target.value)"
                                    class="field w-full min-w-[11rem] px-3 py-2 text-sm">
                                    <option value="">{{ $fieldLabels['ignore'] }}</option>
                                    @foreach (['name', 'price', 'stock', 'category', 'description', 'sku', 'url', 'extra'] as $field)
                                        <option value="{{ $field }}">{{ $fieldLabels[$field] }}</option>
                                    @endforeach
                                </select>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <div class="flex justify-end gap-2 border-t border-hairline px-5 py-4 dark:border-white/10">
            <button type="button" @click="cancel()" :disabled="importing"
                class="rounded-full px-4 py-2 text-sm font-semibold disabled:opacity-60">
                {{ __('agents.catalog.mapping_cancel') }}
            </button>
            <button type="button" @click="submitImport()" :disabled="importing"
                class="btn btn-primary disabled:opacity-60">
                <span x-show="!importing">{{ __('agents.catalog.mapping_import') }}</span>
                <span x-show="importing" x-cloak class="spinner"></span>
            </button>
        </div>
    </div>

    {{-- Current catalog --}}
    <div x-show="!pending" x-cloak>
        <template x-if="!dataset">
            <div class="flex flex-col items-center justify-center gap-2 rounded-2xl px-6 py-12 text-center">
                <svg class="h-8 w-8 text-muted dark:text-night-muted/70" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                <p class="text-sm font-medium">{{ __('agents.catalog.empty_title') }}</p>
                <p class="max-w-sm text-xs text-muted dark:text-night-muted">{{ __('agents.catalog.empty_description') }}</p>
            </div>
        </template>

        <template x-if="dataset">
            <div class="space-y-4">
                <div class="rounded-2xl p-5">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <div class="flex items-center gap-2">
                                <span class="badge badge-success">
                                    {{ __('agents.catalog.status_title') }}
                                </span>
                                <span class="text-sm font-semibold" x-text="rowsLabel.replace(':count', dataset.row_count)"></span>
                            </div>
                            <p class="mt-1.5 text-xs text-muted dark:text-night-muted">
                                <span x-text="dataset.filename"></span>
                                <span x-show="dataset.imported_at"> · <span x-text="updatedLabel.replace(':time', dataset.imported_at)"></span></span>
                            </p>
                            <p x-show="dataset.skipped_count > 0" x-cloak class="mt-1 text-xs text-warning dark:text-warning-light"
                                x-text="skippedSummary()"></p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" @click="replace()"
                                class="flex items-center gap-1.5 rounded-full px-4 py-2 text-sm font-medium transition-all">
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                {{ __('agents.catalog.status_replace') }}
                            </button>
                            <button type="button" @click="destroy()" :disabled="deleting"
                                class="flex items-center gap-1.5 rounded-full px-4 py-2 text-sm font-medium text-danger transition-all disabled:opacity-60">
                                {{ __('agents.catalog.status_delete') }}
                            </button>
                        </div>
                    </div>

                    {{-- The stored mapping, in the file's own column names --}}
                    <div class="mt-4 border-t border-hairline pt-4 dark:border-white/10">
                        <p class="text-xs font-medium uppercase tracking-wide text-muted dark:text-night-muted/70">{{ __('agents.catalog.status_mapping') }}</p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <template x-for="entry in mappingSummary()" :key="entry.field">
                                <span class="badge badge-neutral">
                                    <span class="font-medium" x-text="entry.label"></span>
                                    <span class="text-muted dark:text-night-muted">←</span>
                                    <span class="text-muted dark:text-night-muted" x-text="entry.column"></span>
                                </span>
                            </template>
                        </div>
                    </div>
                </div>

                <div class="overflow-hidden rounded-2xl">
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-hairline px-5 py-3 dark:border-white/10">
                        <span class="text-xs font-medium uppercase tracking-wide text-muted dark:text-night-muted/70">
                            {{ __('agents.catalog.status_preview') }}
                        </span>
                        <span class="text-xs text-muted dark:text-night-muted" x-text="rangeLabel()"></span>
                    </div>
                    <div class="relative overflow-x-auto" :class="pageLoading && 'opacity-50 transition-opacity'">
                        <table class="table-flat">
                            <thead>
                                {{-- Whatever the file actually has. A catalog with no
                                     stock or article column must not be shown two empty
                                     columns headed as if it had them. --}}
                                <tr>
                                    <th class="px-5 py-3 font-medium">{{ __('agents.catalog.product_images.column') }}</th>
                                    <template x-for="column in previewColumns()" :key="column.key">
                                        <th class="px-5 py-3 font-medium" x-text="column.label"></th>
                                    </template>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="(product, index) in preview" :key="index">
                                    <tr class="border-t border-hairline dark:border-white/10">
                                        <td class="px-5 py-3">
                                            <div class="flex items-center gap-1.5">
                                                <template x-for="(image, imgIndex) in (product.images ?? [])" :key="image.id">
                                                    <div class="group relative h-10 w-10 shrink-0">
                                                        <img :src="image.url" :alt="product.name" class="h-10 w-10 rounded-lg object-cover" :class="imgIndex === 0 && 'ring-2 ring-brand'" />
                                                        <div class="absolute inset-0 hidden items-center justify-center gap-0.5 rounded-lg bg-black/50 group-hover:flex">
                                                            <button type="button" x-show="imgIndex > 0" @click="makePrimaryProductImage(product, image)"
                                                                class="text-white" :aria-label="primaryLabel" title="{{ __('agents.catalog.product_images.make_primary') }}">★</button>
                                                            <button type="button" @click="removeProductImage(product, image)"
                                                                class="text-white" :aria-label="removeLabel" title="{{ __('agents.catalog.product_images.remove') }}">✕</button>
                                                        </div>
                                                    </div>
                                                </template>
                                                <button type="button" x-show="(product.images ?? []).length < {{ (int) config('agent.product_images.max_per_product') }}"
                                                    @click="$refs['productImageInput' + product.id].click()"
                                                    class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-dashed border-black/15 text-muted hover:border-brand hover:text-brand dark:border-white/15 dark:text-night-muted"
                                                    :aria-label="addPhotoLabel" title="{{ __('agents.catalog.product_images.add') }}">+</button>
                                                <input type="file" :x-ref="'productImageInput' + product.id" accept="{{ implode(',', array_map(fn ($m) => ".$m", config('agent.product_images.allowed_extensions'))) }}" class="hidden"
                                                    @change="uploadProductImage(product, $event.target.files[0]); $event.target.value = ''" />
                                            </div>
                                        </td>
                                        <template x-for="column in previewColumns()" :key="column.key">
                                            <td class="px-5 py-3"
                                                :class="column.key === 'name'
                                                    ? 'max-w-xs truncate font-medium'
                                                    : 'text-muted dark:text-night-muted'"
                                                x-text="cell(product, column)"></td>
                                        </template>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>

                    {{-- Pager. Hidden for a catalog that fits on one page. --}}
                    <div x-show="pagination.last_page > 1" x-cloak
                        class="flex flex-wrap items-center justify-center gap-1 border-t border-hairline px-5 py-3 dark:border-white/10">
                        <button type="button" @click="goToPage(pagination.page - 1)"
                            :disabled="pagination.page <= 1 || pageLoading"
                            class="flex items-center gap-1 rounded-full px-3 py-1.5 text-sm font-medium text-muted transition-colors hover:text-brand-strong disabled:pointer-events-none disabled:opacity-40 dark:text-night-muted">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                            <span class="hidden sm:inline">{{ __('agents.catalog.pagination.prev') }}</span>
                        </button>

                        <template x-for="(item, index) in pageNumbers()" :key="index">
                            <button type="button"
                                @click="item.gap || goToPage(item.page)"
                                :disabled="item.gap || pageLoading"
                                :aria-current="!item.gap && item.page === pagination.page ? 'page' : false"
                                :aria-label="item.gap ? false : pageAriaLabel.replace(':page', item.page)"
                                :aria-hidden="item.gap"
                                :class="item.gap
                                    ? 'cursor-default text-muted disabled:opacity-100 dark:text-night-muted/70'
                                    : (item.page === pagination.page
                                        ? 'bg-brand text-on-accent'
                                        : 'text-muted hover:bg-sunk hover:text-ink dark:text-night-muted dark:hover:bg-white/5 dark:hover:text-white')"
                                class="min-w-[2rem] rounded-full px-2.5 py-1.5 text-sm font-medium transition-colors disabled:pointer-events-none"
                                x-text="item.gap ? '…' : item.page"></button>
                        </template>

                        <button type="button" @click="goToPage(pagination.page + 1)"
                            :disabled="pagination.page >= pagination.last_page || pageLoading"
                            class="flex items-center gap-1 rounded-full px-3 py-1.5 text-sm font-medium text-muted transition-colors hover:text-brand-strong disabled:pointer-events-none disabled:opacity-40 dark:text-night-muted">
                            <span class="hidden sm:inline">{{ __('agents.catalog.pagination.next') }}</span>
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                        </button>
                    </div>
                </div>
            </div>
        </template>
    </div>
</div>

@once
@push('scripts')
<script>
    function catalogTab({
        productsUrl, analyzeUrl, importUrl, cancelUrl, destroyUrl,
        productImagesUrlBase, productImageMoveUpUrlBase, productImageDeleteUrlBase, initial,
    }) {
        return {
            dataset: initial.dataset,
            preview: initial.preview ?? [],
            pagination: initial.pagination ?? { page: 1, last_page: 1, per_page: 10, total: 0, from: 0, to: 0 },
            pageLoading: false,
            primaryLabel: @json(__('agents.catalog.product_images.make_primary')),
            removeLabel: @json(__('agents.catalog.product_images.remove')),
            addPhotoLabel: @json(__('agents.catalog.product_images.add')),
            // The analyzed-but-unconfirmed upload, or null. Doubles as the
            // "which screen am I on" flag for the whole tab.
            pending: null,
            mapping: {},
            dragging: false,
            uploading: false,
            importing: false,
            deleting: false,
            error: '',
            fieldLabels: @json($fieldLabels),
            rowsLabel: @json(__('agents.catalog.status_rows')),
            rowsFoundLabel: @json(__('agents.catalog.mapping_rows_found')),
            truncatedLabel: @json(__('agents.catalog.mapping_truncated')),
            updatedLabel: @json(__('agents.catalog.status_updated')),
            skippedLabel: @json(__('agents.catalog.status_skipped')),
            skipReasons: @json(__('agents.catalog.skip_reasons')),
            emptyValue: @json(__('agents.catalog.preview.empty')),
            previewLabels: @json(__('agents.catalog.preview')),
            summaryLabel: @json(__('agents.catalog.pagination.summary')),
            pageAriaLabel: @json(__('agents.catalog.pagination.page_aria')),

            csrfHeaders(extra = {}) {
                return {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    ...extra,
                };
            },

            show(value) {
                return value === null || value === undefined || value === '' ? this.emptyValue : value;
            },

            rangeLabel() {
                return this.summaryLabel
                    .replace(':from', this.pagination.from)
                    .replace(':to', this.pagination.to)
                    .replace(':total', this.pagination.total);
            },

            /**
             * The page buttons to render: every page for a short catalog, and
             * for a long one the first, the last, and a window around the
             * current page, with { gap: true } markers standing in for the runs
             * left out. Condensing keeps the control from wrapping into a wall
             * of numbers on a catalog with hundreds of pages.
             */
            pageNumbers() {
                const last = this.pagination.last_page;
                const current = this.pagination.page;

                if (last <= 7) {
                    return Array.from({ length: last }, (_, i) => ({ page: i + 1 }));
                }

                const wanted = new Set([1, last, current - 1, current, current + 1]);

                // Near either end, extend that end's run instead of leaving a
                // gap one page wide — so the control keeps a steady width as the
                // owner pages through rather than jittering.
                if (current <= 4) {
                    [2, 3, 4, 5].forEach((page) => wanted.add(page));
                }
                if (current >= last - 3) {
                    [last - 1, last - 2, last - 3, last - 4].forEach((page) => wanted.add(page));
                }

                const pages = [...wanted]
                    .filter((page) => page >= 1 && page <= last)
                    .sort((a, b) => a - b);

                const items = [];
                let previous = 0;
                for (const page of pages) {
                    if (previous && page - previous > 1) items.push({ gap: true });
                    items.push({ page });
                    previous = page;
                }

                return items;
            },

            async goToPage(page) {
                if (this.pageLoading) return;
                if (page < 1 || page > this.pagination.last_page || page === this.pagination.page) return;

                this.pageLoading = true;

                try {
                    const res = await fetch(`${productsUrl}?page=${page}`, { headers: { Accept: 'application/json' } });
                    if (!res.ok) {
                        window.showToast?.(@json(__('agents.catalog.toasts.network_error')), 'error');
                        return;
                    }
                    const data = await res.json();
                    this.preview = data.preview ?? [];
                    this.pagination = data.pagination;
                } catch (e) {
                    window.showToast?.(@json(__('agents.catalog.toasts.network_error')), 'error');
                } finally {
                    this.pageLoading = false;
                }
            },

            /** Which field, if any, this column index is currently assigned to. */
            fieldFor(index) {
                for (const [field, value] of Object.entries(this.mapping)) {
                    if (field === 'extra') {
                        if (Array.isArray(value) && value.includes(index)) return 'extra';
                    } else if (value === index) {
                        return field;
                    }
                }
                return '';
            },

            /**
             * Assign a column to a field, enforcing one-column-per-field: picking
             * "Цена" for a second column moves it rather than creating a second
             * price the importer would have to arbitrate between.
             */
            assign(index, field) {
                for (const key of Object.keys(this.mapping)) {
                    if (key === 'extra') {
                        this.mapping.extra = (this.mapping.extra ?? []).filter((i) => i !== index);
                        if (this.mapping.extra.length === 0) delete this.mapping.extra;
                    } else if (this.mapping[key] === index) {
                        delete this.mapping[key];
                    }
                }

                if (field === 'extra') {
                    this.mapping.extra = [...(this.mapping.extra ?? []), index];
                } else if (field !== '') {
                    this.mapping[field] = index;
                }
            },

            sampleFor(index) {
                return (this.pending?.sample ?? [])
                    .map((row) => row[index])
                    .filter((value) => value !== undefined && value !== null && value !== '')
                    .slice(0, 3)
                    .join(' · ') || this.emptyValue;
            },

            /**
             * The preview's columns, taken from what this file was mapped to.
             *
             * The core fields the owner actually assigned, in a fixed order, then
             * the file's own attribute columns under their own header names. A
             * catalog with no Артикул column simply has no Артикул column here —
             * showing one full of dashes reads as an import that lost the data.
             */
            previewColumns() {
                const mapping = this.dataset?.mapping ?? {};
                const columns = this.dataset?.columns ?? [];

                // A catalog imported before mappings were stored still has to
                // render something: fall back to the core fields.
                if (Object.keys(mapping).length === 0) {
                    return ['name', 'price', 'stock', 'category', 'sku']
                        .map((field) => ({ key: field, label: this.previewLabels[field] ?? field, extra: false }));
                }

                const entries = [];

                for (const field of ['name', 'price', 'stock', 'category', 'sku']) {
                    if (mapping[field] !== undefined && mapping[field] !== null) {
                        entries.push({ key: field, label: this.previewLabels[field] ?? field, extra: false });
                    }
                }

                for (const index of mapping.extra ?? []) {
                    const header = columns[index] ?? '';
                    if (header !== '') {
                        entries.push({ key: `extra:${header}`, label: header, extra: true, header });
                    }
                }

                return entries;
            },

            cell(product, column) {
                return this.show(column.extra ? (product.extra ?? {})[column.header] : product[column.key]);
            },

            mappingSummary() {
                const mapping = this.dataset?.mapping ?? {};
                const columns = this.dataset?.columns ?? [];
                const entries = [];

                for (const [field, value] of Object.entries(mapping)) {
                    if (field === 'extra') {
                        for (const index of value ?? []) {
                            entries.push({ field: `extra-${index}`, label: this.fieldLabels.extra, column: columns[index] ?? '?' });
                        }
                    } else {
                        entries.push({ field, label: this.fieldLabels[field] ?? field, column: columns[value] ?? '?' });
                    }
                }

                return entries;
            },

            skippedSummary() {
                const total = this.skippedLabel.replace(':count', this.dataset?.skipped_count ?? 0);
                const reasons = Object.entries(this.dataset?.skipped_reasons ?? {})
                    .map(([reason, count]) => (this.skipReasons[reason] ?? reason).replace(':count', count));

                return reasons.length ? `${total} (${reasons.join(', ')})` : total;
            },

            async handleFile(file) {
                if (!file) return;
                if (this.dataset && !confirm(@json(__('agents.catalog.toasts.replace_confirm')))) return;

                this.uploading = true;
                this.error = '';

                const body = new FormData();
                body.append('file', file);

                try {
                    const res = await fetch(analyzeUrl, { method: 'POST', headers: this.csrfHeaders(), body });
                    const data = await res.json().catch(() => ({}));

                    if (!res.ok) {
                        window.showToast?.(data.message || data.errors?.file?.[0] || @json(__('agents.catalog.errors.unreadable')), 'error');
                        return;
                    }

                    this.pending = data;
                    this.mapping = { ...data.mapping };
                } catch (e) {
                    window.showToast?.(@json(__('agents.catalog.toasts.network_error')), 'error');
                } finally {
                    this.uploading = false;
                }
            },

            async submitImport() {
                this.importing = true;
                this.error = '';

                try {
                    const res = await fetch(importUrl, {
                        method: 'POST',
                        headers: this.csrfHeaders({ 'Content-Type': 'application/json' }),
                        body: JSON.stringify({ token: this.pending.token, mapping: this.mapping }),
                    });
                    const data = await res.json().catch(() => ({}));

                    if (!res.ok) {
                        // Kept inline rather than as a toast: the fix is a
                        // different choice in the table right below it.
                        this.error = data.message || data.errors?.mapping?.[0] || @json(__('agents.catalog.errors.import_failed'));
                        return;
                    }

                    this.dataset = data.dataset;
                    this.preview = data.preview ?? [];
                    // A fresh import always lands the owner back on page 1 —
                    // the page they were on described a catalog that no longer
                    // exists.
                    this.pagination = data.pagination;
                    this.pending = null;
                    this.mapping = {};

                    const message = data.skipped > 0
                        ? @json(__('agents.catalog.toasts.imported_skipped')).replace(':count', data.imported).replace(':skipped', data.skipped)
                        : @json(__('agents.catalog.toasts.imported')).replace(':count', data.imported);
                    window.showToast?.(message, 'success');
                } catch (e) {
                    window.showToast?.(@json(__('agents.catalog.toasts.network_error')), 'error');
                } finally {
                    this.importing = false;
                }
            },

            async cancel() {
                const token = this.pending?.token;
                this.pending = null;
                this.mapping = {};
                this.error = '';

                if (!token) return;

                try {
                    await fetch(cancelUrl, {
                        method: 'POST',
                        headers: this.csrfHeaders({ 'Content-Type': 'application/json' }),
                        body: JSON.stringify({ token }),
                    });
                } catch (e) { /* the sweep will collect it */ }
            },

            replace() {
                this.$refs.fileInput.click();
            },

            /** Uploads one photo for a row and merges the response back into its own preview entry. */
            async uploadProductImage(product, file) {
                if (!file) return;

                const body = new FormData();
                body.append('image', file);

                try {
                    const res = await fetch(productImagesUrlBase.replace('__ID__', product.id), {
                        method: 'POST', headers: this.csrfHeaders(), body,
                    });
                    const data = await res.json().catch(() => ({}));
                    if (!res.ok) {
                        window.showToast?.(data.message || data.errors?.image?.[0] || @json(__('agents.catalog.product_images.errors.upload_failed')), 'error');
                        return;
                    }
                    product.images = [...(product.images ?? []), data.image];
                } catch (e) {
                    window.showToast?.(@json(__('agents.catalog.toasts.network_error')), 'error');
                }
            },

            /** Swaps a photo with its predecessor; reaching position 0 makes it the one the agent sends first. */
            async makePrimaryProductImage(product, image) {
                try {
                    const res = await fetch(productImageMoveUpUrlBase.replace('__PRODUCT__', product.id).replace('__IMAGE__', image.id), {
                        method: 'POST', headers: this.csrfHeaders(),
                    });
                    if (!res.ok) {
                        window.showToast?.(@json(__('agents.catalog.toasts.network_error')), 'error');
                        return;
                    }
                    product.images = (await res.json()).images;
                } catch (e) {
                    window.showToast?.(@json(__('agents.catalog.toasts.network_error')), 'error');
                }
            },

            async removeProductImage(product, image) {
                if (!confirm(@json(__('agents.catalog.product_images.delete_confirm')))) return;

                try {
                    const res = await fetch(productImageDeleteUrlBase.replace('__PRODUCT__', product.id).replace('__IMAGE__', image.id), {
                        method: 'DELETE', headers: this.csrfHeaders(),
                    });
                    if (!res.ok) {
                        window.showToast?.(@json(__('agents.catalog.toasts.network_error')), 'error');
                        return;
                    }
                    product.images = (await res.json()).images;
                } catch (e) {
                    window.showToast?.(@json(__('agents.catalog.toasts.network_error')), 'error');
                }
            },

            async destroy() {
                if (!confirm(@json(__('agents.catalog.toasts.delete_confirm')))) return;
                this.deleting = true;

                try {
                    const res = await fetch(destroyUrl, { method: 'DELETE', headers: this.csrfHeaders() });
                    if (!res.ok) {
                        window.showToast?.(@json(__('agents.catalog.toasts.delete_failed')), 'error');
                        return;
                    }
                    const data = await res.json();
                    this.dataset = data.dataset;
                    this.preview = data.preview ?? [];
                    this.pagination = data.pagination;
                    window.showToast?.(@json(__('agents.catalog.toasts.deleted')), 'success');
                } catch (e) {
                    window.showToast?.(@json(__('agents.catalog.toasts.network_error')), 'error');
                } finally {
                    this.deleting = false;
                }
            },
        };
    }
</script>
@endpush
@endonce
