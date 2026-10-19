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
        previewUpdateUrl: '{{ route('agents.catalog.preview_update', $agent) }}',
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

        {{-- The owner's one chance to see, before anything is written, how
             many of their existing rows this mapping does not match — and to
             opt out of the out-of-stock side effect of that, not just the
             match itself. x-show rather than x-if: missingPreview can still
             be loading (null) right after a mapping change. --}}
        <template x-if="dataset">
            <div x-show="missingPreview > 0" x-cloak class="border-t border-hairline px-5 py-4 dark:border-white/10">
                {{-- The warning styling only applies while the checkbox below
                     is actually going to zero those rows' stock; unchecked,
                     nothing bad is about to happen, so the same count gets a
                     neutral note instead of a warning that no longer describes
                     what submitting will do. --}}
                <p :class="markMissing ? 'text-warning dark:text-warning-light' : 'text-muted dark:text-night-muted'" class="text-xs"
                    x-text="(markMissing ? missingWarningLabel : missingNeutralLabel).replace(':count', missingPreview)"></p>
                <label class="mt-2 flex items-center gap-2 text-xs text-muted dark:text-night-muted">
                    <input type="checkbox" x-model="markMissing" class="rounded border-black/20 dark:border-white/20" />
                    <span x-text="markMissingLabel"></span>
                </label>
            </div>
        </template>

        {{-- Two paths once a catalog already exists: 'update' (default,
             matches existing rows by sku/name, keeps every uploaded photo,
             marks whatever the file no longer lists as out of stock rather
             than deleting it) or 'replace' — the original, fully
             destructive behavior, demoted to a secondary action with its
             own confirmation precisely because it deletes every photo. A
             first-ever upload has nothing to update against, so it only
             ever offers the one plain import button. --}}
        <div class="flex flex-wrap items-center justify-end gap-2 border-t border-hairline px-5 py-4 dark:border-white/10">
            <button type="button" @click="cancel()" :disabled="importing"
                class="rounded-full px-4 py-2 text-sm font-semibold disabled:opacity-60">
                {{ __('agents.catalog.mapping_cancel') }}
            </button>
            <template x-if="dataset">
                <button type="button" @click="confirmReplace()" :disabled="importing"
                    class="rounded-full px-4 py-2 text-sm font-medium text-danger disabled:opacity-60">
                    {{ __('agents.catalog.mapping_replace') }}
                </button>
            </template>
            <button type="button" @click="submitImport(dataset ? 'update' : 'replace')" :disabled="importing"
                class="btn btn-primary disabled:opacity-60">
                <span x-show="!importing" x-text="dataset ? @js(__('agents.catalog.mapping_update')) : @js(__('agents.catalog.mapping_import'))"></span>
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
                            <p class="mt-1.5 text-xs" :class="dataset.stale ? 'text-warning dark:text-warning-light' : 'text-muted dark:text-night-muted'">
                                <span x-text="dataset.filename"></span>
                                <span x-show="dataset.imported_at"> · <span x-text="updatedLabel.replace(':time', dataset.imported_at)"></span></span>
                            </p>
                            <p x-show="dataset.stale" x-cloak class="mt-1 text-xs text-warning dark:text-warning-light">{{ __('agents.catalog.stale_hint') }}</p>
                            <p x-show="dataset.skipped_count > 0" x-cloak class="mt-1 text-xs text-warning dark:text-warning-light"
                                x-text="skippedSummary()"></p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" @click="replace()"
                                class="flex items-center gap-1.5 rounded-full px-4 py-2 text-sm font-medium transition-all">
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                {{ __('agents.catalog.status_update') }}
                            </button>
                            <button type="button" @click="deleteCatalog()" :disabled="deleting"
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

                {{-- ONE hidden input, outside the x-for below. A per-row
                     :x-ref computed from product.id looked right but Alpine's
                     $refs registry does not reliably track a ref whose name
                     is itself reactive inside a loop — see pickProductPhoto()
                     for what actually goes wrong and why this is the fix. --}}
                <input type="file" x-ref="productPhotoInput" accept="{{ implode(',', array_map(fn ($m) => ".$m", config('agent.product_images.allowed_extensions'))) }}" class="hidden"
                    @change="onProductPhotoPicked($event)" />

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
                                            <div class="flex items-center gap-2.5">
                                                <template x-for="(image, imgIndex) in (product.images ?? [])" :key="image.id">
                                                    {{-- Two boxes: the OUTER one is `relative`, un-clipped, and carries
                                                         the tile's own border — ✕ and the ★ badge both live on it, so
                                                         neither is ever cut off by the INNER box's overflow-hidden,
                                                         which exists only to round/clip the photo itself. There is no
                                                         full-tile overlay any more (the old ★ "make primary" dark
                                                         overlay used to sit right on top of ✕ and win every hover/
                                                         click there, confirmed by hit-testing this live) — ★ is now a
                                                         small corner badge with nothing else sharing its stacking, so
                                                         there is nothing left for ✕ to contend with. --}}
                                                    <div x-data="{ failed: false, retried: false }"
                                                        class="group relative h-12 w-12 shrink-0 rounded-lg border border-hairline dark:border-white/10"
                                                        x-transition:leave="transition ease-in duration-150"
                                                        x-transition:leave-start="opacity-100 scale-100"
                                                        x-transition:leave-end="opacity-0 scale-90">
                                                        <div class="h-full w-full overflow-hidden rounded-lg bg-sunk dark:bg-white/5">
                                                            <img x-show="!failed" :src="image.url" alt="" loading="lazy" decoding="async"
                                                                class="h-full w-full object-cover"
                                                                x-on:error="retried ? (failed = true) : (retried = true, $el.src = image.url + (image.url.includes('?') ? '&' : '?') + 'retry=' + Date.now())" />
                                                            {{-- A failed/still-broken photo keeps the tile's size and
                                                                 border — no collapse, no alt-text overflow — and keeps
                                                                 its ✕, since that lives outside this box entirely. --}}
                                                            <div x-show="failed" x-cloak class="flex h-full w-full items-center justify-center text-muted dark:text-night-muted/60">
                                                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14M4 6h16v12H4V6z"/></svg>
                                                            </div>
                                                        </div>

                                                        {{-- Primary photo: a persistent, subtle gold marker — not a
                                                             button, nothing to click, it already IS primary. Every
                                                             other photo gets a plain promote button in the same spot,
                                                             hover/focus-only. --}}
                                                        <template x-if="imgIndex === 0">
                                                            <span class="pointer-events-none absolute -bottom-1 -left-1 z-10 flex h-4 w-4 items-center justify-center rounded-full bg-amber-50 text-[9px] leading-none text-amber-500 shadow-sm ring-1 ring-amber-200 dark:bg-amber-400/10 dark:text-amber-300 dark:ring-amber-300/30"
                                                                title="{{ __('agents.catalog.product_images.primary_badge') }}">★</span>
                                                        </template>
                                                        <template x-if="imgIndex > 0">
                                                            <button type="button" @click="makePrimaryProductImage(product, image)"
                                                                class="absolute -bottom-1 -left-1 z-10 flex h-4 w-4 items-center justify-center rounded-full bg-white/90 text-[9px] leading-none text-amber-500 opacity-0 shadow-sm ring-1 ring-amber-200 transition-opacity duration-150 hover:bg-amber-50 group-hover:opacity-100 group-focus-within:opacity-100 focus-visible:opacity-100 dark:bg-night-ink/90 dark:text-amber-300 dark:ring-amber-300/30"
                                                                :aria-label="primaryLabel" title="{{ __('agents.catalog.product_images.make_primary') }}">★</button>
                                                        </template>

                                                        {{-- Neutral gray, not red/black: this is a reversible removal
                                                             (there's a 5s undo bar), not a destructive-looking action.
                                                             z-20 keeps it above everything else in the tile. Visible
                                                             at reduced opacity with no hover-capable pointer (touch),
                                                             since there's no hover there to reveal it with. --}}
                                                        <button type="button" @click="removeProductImage(product, image)"
                                                            class="absolute -right-1.5 -top-1.5 z-20 flex h-5 w-5 items-center justify-center rounded-full border border-gray-200 bg-white/90 text-xs leading-none text-gray-500 opacity-0 shadow-sm transition-opacity duration-150 hover:bg-gray-100 hover:text-gray-700 group-hover:opacity-100 group-focus-within:opacity-100 focus-visible:opacity-100 [@media(hover:none)]:opacity-70 dark:border-white/10 dark:bg-night-ink/90 dark:text-night-muted dark:hover:bg-white/10 dark:hover:text-night-ink"
                                                            :aria-label="removeLabel" title="{{ __('agents.catalog.product_images.remove') }}">✕</button>
                                                    </div>
                                                </template>
                                                <button type="button" x-show="(product.images ?? []).length < {{ (int) config('agent.product_images.max_per_product') }}"
                                                    @click="pickProductPhoto(product)"
                                                    class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg border border-dashed border-black/15 text-muted hover:border-brand hover:text-brand dark:border-white/15 dark:text-night-muted"
                                                    :aria-label="addPhotoLabel" title="{{ __('agents.catalog.product_images.add') }}">+</button>
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

    {{-- window.showToast is text-only and auto-dismisses — no room for an
         "Отменить" action — so photo removal gets its own small inline bar
         instead, one per still-pending removal (several quick removals each
         get their own timer and their own bar; see removeProductImage()). --}}
    <div class="pointer-events-none fixed inset-x-0 bottom-4 z-40 flex flex-col items-center gap-2 px-4" x-show="photoRemovals.length > 0" x-cloak>
        <template x-for="removal in photoRemovals" :key="removal.id">
            <div class="pointer-events-auto flex items-center gap-3 rounded-full bg-ink px-4 py-2 text-sm font-medium text-white shadow-xl dark:bg-night-ink dark:text-night">
                <span>{{ __('agents.catalog.product_images.removed') }}</span>
                <button type="button" @click="undoPhotoRemoval(removal.id)" class="font-semibold text-brand-light hover:underline">{{ __('agents.catalog.product_images.undo') }}</button>
            </div>
        </template>
    </div>
</div>

@once
@push('scripts')
<script>
    function catalogTab({
        productsUrl, analyzeUrl, previewUpdateUrl, importUrl, cancelUrl, destroyUrl,
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
            // How many existing rows the current mapping doesn't match, or
            // null while that count hasn't loaded yet — see requestMissingPreview().
            missingPreview: null,
            missingPreviewSeq: 0,
            markMissing: true,
            // Which product the ONE shared file input (see pickProductPhoto())
            // is currently picking a photo for.
            photoTarget: null,
            // Removals waiting out their undo window — [{ id, product, image, index, timer }].
            // See removeProductImage()/undoPhotoRemoval()/commitPhotoRemoval().
            photoRemovals: [],
            // Image ids with a DELETE currently in flight — kept separately
            // from photoRemovals because an entry leaves THAT array the
            // instant its commit starts, before the request it kicked off
            // has actually resolved. Without a guard that covers that whole
            // window, a second click landing in it looked like "not
            // pending" and started an independent second removal for the
            // same photo, which eventually sent a second DELETE for a row
            // the first one had already removed.
            inFlightRemovalIds: new Set(),
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
            missingWarningLabel: @json(__('agents.catalog.mapping_missing_warning')),
            missingNeutralLabel: @json(__('agents.catalog.mapping_missing_neutral')),
            markMissingLabel: @json(__('agents.catalog.mapping_mark_missing')),
            networkErrorLabel: @json(__('agents.catalog.toasts.network_error')),
            actionFailedLabel: @json(__('agents.catalog.toasts.action_failed')),
            unexpectedResponseLabel: @json(__('agents.catalog.toasts.unexpected_response')),

            /** Flushes any removal still waiting out its undo window before the tab is actually gone — otherwise a photo that looked removed in the UI could silently survive server-side forever. */
            init() {
                window.addEventListener('beforeunload', () => this.flushPhotoRemovals());
            },

            // Last {message: type} shown and when, for showToast()'s dedup window below.
            lastToast: null,

            /**
             * Every toast in this component goes through here instead of
             * `window.showToast` directly, so the SAME message within 3s
             * only ever shows once. Needed because a guarded-but-still-
             * racy double action (two near-simultaneous DELETEs that both
             * fail the same way, for instance) can otherwise fire the exact
             * same error toast twice in a row, which just reads as the
             * action having failed twice.
             */
            showToast(message, type = 'info') {
                const now = Date.now();
                if (this.lastToast && this.lastToast.message === message && this.lastToast.type === type && now - this.lastToast.at < 3000) {
                    return;
                }
                this.lastToast = { message, type, at: now };
                window.showToast?.(message, type);
            },

            csrfHeaders(extra = {}) {
                return {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    ...extra,
                };
            },

            /**
             * Reads a fetch response as JSON without assuming it IS JSON.
             * A 200 with an HTML body — most commonly a tunnel/proxy's own
             * click-through interstitial page standing in for the real
             * response (ngrok's free tier does this to an unrecognized
             * visitor) — must not be handed to code that expects
             * `data.image`/`data.images`/etc. and will otherwise fail
             * confusingly further down. Returns { data, html }: `html` true
             * means "not JSON at all", `data` null when there was nothing
             * (or nothing parseable) to read.
             */
            async readJson(res) {
                const contentType = res.headers.get('content-type') || '';
                if (!contentType.includes('application/json')) {
                    return { data: null, html: true };
                }

                try {
                    return { data: await res.json(), html: false };
                } catch (e) {
                    return { data: null, html: false };
                }
            },

            /**
             * One place for "the server answered, just not with success" —
             * a real HTTP error (4xx/5xx) or a 200 that wasn't JSON. Shows
             * the server's own JSON message when there is one; a non-JSON
             * response or a JSON error with no message of its own gets a
             * generic, status-aware fallback instead of the misleading
             * "no connection" toast every one of these used to get
             * regardless of status code. Always logged with the status and
             * body so a real failure is still diagnosable from devtools.
             */
            reportHttpFailure(res, data, html) {
                console.error('Catalog request failed', res.status, html ? '(non-JSON response)' : data);

                const message = html
                    ? this.unexpectedResponseLabel
                    : (data?.message || this.actionFailedLabel.replace(':code', res.status));

                this.showToast(message, 'error');
            },

            /** The fetch itself threw — offline, DNS, CORS, an aborted connection. This is the one case "no connection to the server" is actually true. */
            reportNetworkFailure(error) {
                console.error('Catalog request threw', error);
                this.showToast(this.networkErrorLabel, 'error');
            },

            /** Drops any image still inside its undo window for this product from a server-sourced list, so re-applying a DELETE/move-up/upload response can never re-show a photo the owner just removed (see removeProductImage()). */
            filterPendingImages(product, images) {
                const pendingIds = new Set(
                    this.photoRemovals.filter((removal) => removal.product.id === product.id).map((removal) => removal.id),
                );

                return (images ?? []).filter((image) => !pendingIds.has(image.id));
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

                // This page's `preview` array is about to be replaced
                // wholesale — any removal still waiting out its undo window
                // on THIS page would otherwise close over a `product` object
                // that is no longer shown anywhere.
                this.flushPhotoRemovals();
                this.pageLoading = true;

                try {
                    const res = await fetch(`${productsUrl}?page=${page}`, { headers: { Accept: 'application/json' } });
                    const { data, html } = await this.readJson(res);
                    if (!res.ok || html) {
                        this.reportHttpFailure(res, data, html);
                        return;
                    }
                    this.preview = data.preview ?? [];
                    this.pagination = data.pagination;
                } catch (e) {
                    this.reportNetworkFailure(e);
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

                this.requestMissingPreview();
            },

            /**
             * Asks the backend how many existing rows the CURRENT mapping
             * wouldn't match, so the mapping step can show a real count
             * before the owner commits to 'update' rather than a guess.
             * Only meaningful once a catalog already exists to match
             * against; `seq` discards a response that lost the race to a
             * newer mapping change.
             */
            async requestMissingPreview() {
                if (!this.dataset || !this.pending) {
                    this.missingPreview = null;
                    return;
                }

                const seq = ++this.missingPreviewSeq;

                try {
                    const res = await fetch(previewUpdateUrl, {
                        method: 'POST',
                        headers: this.csrfHeaders({ 'Content-Type': 'application/json' }),
                        body: JSON.stringify({ token: this.pending.token, mapping: this.mapping }),
                    });
                    const { data, html } = await this.readJson(res);
                    if (!res.ok || html || seq !== this.missingPreviewSeq) return;
                    this.missingPreview = data.missing ?? 0;
                } catch (e) {
                    // Advisory only — a failed preview just leaves the hint hidden,
                    // with no toast of its own; still worth a console trace.
                    console.error('Catalog preview request threw', e);
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

                // No confirmation here any more: the mapping step below is
                // where the owner actually chooses update vs. replace, and
                // only the latter needs a warning — see confirmReplace().
                this.uploading = true;
                this.error = '';

                const body = new FormData();
                body.append('file', file);

                try {
                    const res = await fetch(analyzeUrl, { method: 'POST', headers: this.csrfHeaders(), body });
                    const { data, html } = await this.readJson(res);

                    if (!res.ok || html) {
                        console.error('Catalog analyze failed', res.status, html ? '(non-JSON response)' : data);
                        this.showToast(
                            html ? this.unexpectedResponseLabel
                                : (data?.message || data?.errors?.file?.[0] || @json(__('agents.catalog.errors.unreadable'))),
                            'error',
                        );
                        return;
                    }

                    this.pending = data;
                    this.mapping = { ...data.mapping };
                    this.markMissing = true;
                    this.requestMissingPreview();
                } catch (e) {
                    this.reportNetworkFailure(e);
                } finally {
                    this.uploading = false;
                }
            },

            /** 'replace' needs the owner's explicit confirmation first — it deletes every uploaded photo; 'update' needs none, since nothing is deleted. */
            confirmReplace() {
                if (confirm(@json(__('agents.catalog.toasts.replace_confirm')))) {
                    this.submitImport('replace');
                }
            },

            async submitImport(mode) {
                // The whole catalog is about to be replaced out from under
                // whatever's currently on screen.
                this.flushPhotoRemovals();
                this.importing = true;
                this.error = '';

                try {
                    const res = await fetch(importUrl, {
                        method: 'POST',
                        headers: this.csrfHeaders({ 'Content-Type': 'application/json' }),
                        body: JSON.stringify({
                            token: this.pending.token, mapping: this.mapping, mode,
                            mark_missing_out_of_stock: this.markMissing,
                        }),
                    });
                    const { data, html } = await this.readJson(res);

                    if (!res.ok || html) {
                        console.error('Catalog import failed', res.status, html ? '(non-JSON response)' : data);
                        // Kept inline rather than as a toast: the fix is a
                        // different choice in the table right below it.
                        this.error = html
                            ? this.unexpectedResponseLabel
                            : (data?.message || data?.errors?.mapping?.[0] || @json(__('agents.catalog.errors.import_failed')));
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
                    this.missingPreview = null;

                    this.showToast(this.importSummary(data), 'success');
                } catch (e) {
                    this.reportNetworkFailure(e);
                } finally {
                    this.importing = false;
                }
            },

            /** 'update' reports what it matched/added/zeroed out; 'replace' keeps the plain "imported N" wording it always had. */
            importSummary(data) {
                if (data.mode === 'update') {
                    let message = @json(__('agents.catalog.toasts.updated'))
                        .replace(':updated', data.updated).replace(':added', data.added).replace(':out_of_stock', data.out_of_stock);
                    if (data.ambiguous > 0) {
                        message += ' ' + @json(__('agents.catalog.toasts.ambiguous')).replace(':count', data.ambiguous);
                    }
                    if (data.skipped > 0) {
                        message += ' ' + @json(__('agents.catalog.toasts.skipped_suffix')).replace(':count', data.skipped);
                    }

                    return message;
                }

                return data.skipped > 0
                    ? @json(__('agents.catalog.toasts.imported_skipped')).replace(':count', data.imported).replace(':skipped', data.skipped)
                    : @json(__('agents.catalog.toasts.imported')).replace(':count', data.imported);
            },

            async cancel() {
                const token = this.pending?.token;
                this.pending = null;
                this.mapping = {};
                this.missingPreview = null;
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

            /**
             * Opens the native file picker for one row's "+" button.
             *
             * Root cause of the bug this replaces: the per-row input used to
             * be INSIDE the x-for, named dynamically via `:x-ref="'productImageInput' + product.id"`.
             * Alpine's `$refs` is a single flat registry resolved once per
             * directive walk; a ref name that is itself a reactive
             * expression evaluated inside a loop is not reliably
             * (re-)registered as the x-for list is diffed/rebuilt (every
             * page fetch replaces `preview` wholesale, and every upload
             * replaces `product.images`), so `$refs[...]` could resolve to
             * `undefined` or to a stale, already-detached `<input>` —
             * `.click()` on either does nothing visible, which is exactly
             * the "button does nothing" symptom. One static input OUTSIDE
             * the loop, targeted by this method instead of a computed ref
             * name, has no such ambiguity.
             */
            pickProductPhoto(product) {
                this.photoTarget = product;
                this.$refs.productPhotoInput.click();
            },

            /** The shared input's own @change — uploads for whichever row pickProductPhoto() last set, then resets both the input and the target. */
            onProductPhotoPicked(event) {
                const file = event.target.files[0];
                const product = this.photoTarget;
                event.target.value = '';
                this.photoTarget = null;

                if (file && product) {
                    this.uploadProductImage(product, file);
                }
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
                    const { data, html } = await this.readJson(res);
                    if (!res.ok || html) {
                        console.error('Product image upload failed', res.status, html ? '(non-JSON response)' : data);
                        this.showToast(
                            html ? this.unexpectedResponseLabel
                                : (data?.message || data?.errors?.image?.[0] || @json(__('agents.catalog.product_images.errors.upload_failed'))),
                            'error',
                        );
                        return;
                    }
                    product.images = this.filterPendingImages(product, [...(product.images ?? []), data.image]);
                } catch (e) {
                    this.reportNetworkFailure(e);
                }
            },

            /** Swaps a photo with its predecessor; reaching position 0 makes it the one the agent sends first. */
            async makePrimaryProductImage(product, image) {
                try {
                    const res = await fetch(productImageMoveUpUrlBase.replace('__PRODUCT__', product.id).replace('__IMAGE__', image.id), {
                        method: 'POST', headers: this.csrfHeaders(),
                    });
                    const { data, html } = await this.readJson(res);
                    if (!res.ok || html) {
                        this.reportHttpFailure(res, data, html);
                        return;
                    }
                    // filterPendingImages(): the server's list is the whole
                    // row's current photos, including any still waiting out
                    // ANOTHER removal's undo window — which this response
                    // would otherwise bring right back onto the screen.
                    product.images = this.filterPendingImages(product, data.images);
                } catch (e) {
                    this.reportNetworkFailure(e);
                }
            },

            /**
             * ✕ no longer asks via confirm() — it hides the photo right
             * away and starts a 5s undo window (see the inline bar at the
             * bottom of the template); the DELETE request itself only goes
             * out once that window elapses, in commitPhotoRemoval(). Each
             * removal gets its own entry/timer, so removing several photos
             * in quick succession undoes independently rather than only the
             * most recent one.
             *
             * The in-flight guard below is the fix for a real double-DELETE
             * bug: a rapid double/triple click on the SAME ✕ can fire this
             * handler more than once before Alpine removes the (now gone)
             * photo's row from the DOM. The old code only checked
             * `photoRemovals`, and committing a removal drops its entry
             * from THAT array the instant the commit starts — before the
             * DELETE it just kicked off has resolved. A click landing in
             * that gap read as "nothing pending" and started a brand new,
             * independently-timed removal for the same image id, which
             * eventually sent a SECOND DELETE for a row the first one had
             * already removed — the server's 404 for it then surfaced as a
             * raw "No query results for model [ProductImage] N" toast.
             * `inFlightRemovalIds` stays set for the request's whole
             * lifetime, closing that gap.
             */
            removeProductImage(product, image) {
                if (this.photoRemovals.some((removal) => removal.id === image.id) || this.inFlightRemovalIds.has(image.id)) {
                    return;
                }

                const images = product.images ?? [];
                const index = images.indexOf(image);
                if (index === -1) {
                    return;
                }

                product.images = images.filter((candidate) => candidate.id !== image.id);

                const removal = { id: image.id, product, image, index };
                removal.timer = setTimeout(() => this.commitPhotoRemoval(removal.id), 5000);
                this.photoRemovals.push(removal);
            },

            /** Restores the thumbnail at its original position and cancels the pending DELETE entirely — nothing was ever sent. */
            undoPhotoRemoval(id) {
                const index = this.photoRemovals.findIndex((removal) => removal.id === id);
                if (index === -1) return;

                const [removal] = this.photoRemovals.splice(index, 1);
                clearTimeout(removal.timer);
                this.restoreRemovedPhoto(removal);
            },

            restoreRemovedPhoto(removal) {
                const images = [...(removal.product.images ?? [])];
                images.splice(Math.min(removal.index, images.length), 0, removal.image);
                removal.product.images = images;
            },

            /**
             * The undo window elapsed (or something flushed it early) — now
             * actually delete it. Guaranteed to run at most once per id:
             * the very first thing it does is leave `photoRemovals` (so a
             * second call for the same id — the timer firing right as
             * flushPhotoRemovals() also reaches it, say — finds nothing and
             * returns immediately) and record the id as in-flight for the
             * DELETE's whole lifetime (so removeProductImage() can't start
             * a fresh, independent removal for it either; see there).
             * `keepalive` gives this a real chance to finish even when it's
             * the beforeunload handler firing this as the tab closes.
             */
            async commitPhotoRemoval(id) {
                const index = this.photoRemovals.findIndex((removal) => removal.id === id);
                if (index === -1) return;

                const [removal] = this.photoRemovals.splice(index, 1);
                clearTimeout(removal.timer);
                this.inFlightRemovalIds.add(id);

                try {
                    const res = await fetch(productImageDeleteUrlBase.replace('__PRODUCT__', removal.product.id).replace('__IMAGE__', removal.id), {
                        method: 'DELETE', headers: this.csrfHeaders(), keepalive: true,
                    });

                    // A 404 here means the row is already gone — exactly
                    // the outcome this removal wanted. The backend now
                    // answers its OWN already-deleted case with a plain 200
                    // (see ProductImageController::destroy()), so this is
                    // just a defensive second line: a stale frontend, a
                    // request that raced something else server-side, or
                    // any other path that still produces a 404 must never
                    // read as an error toast for a removal that, in effect,
                    // already succeeded.
                    if (res.status === 404) {
                        removal.product.images = this.filterPendingImages(removal.product, removal.product.images ?? []);
                        return;
                    }

                    const { data, html } = await this.readJson(res);
                    if (!res.ok || html) {
                        // The delete never actually happened — give the photo back.
                        this.reportHttpFailure(res, data, html);
                        this.restoreRemovedPhoto(removal);
                        return;
                    }
                    // filterPendingImages(): a second, still-pending removal
                    // on the same product must not be resurrected by THIS
                    // removal's own server response.
                    removal.product.images = this.filterPendingImages(removal.product, data.images);
                } catch (e) {
                    this.reportNetworkFailure(e);
                    this.restoreRemovedPhoto(removal);
                } finally {
                    this.inFlightRemovalIds.delete(id);
                }
            },

            /**
             * Sends every still-pending removal's DELETE immediately instead
             * of waiting out its timer. Called whenever the row list a
             * pending removal points at is about to be replaced out from
             * under it — a page change, a fresh import, or deleting the
             * whole catalog — so a removal never silently outlives the
             * `product` object its timer closed over.
             */
            flushPhotoRemovals() {
                [...this.photoRemovals].forEach((removal) => this.commitPhotoRemoval(removal.id));
            },

            // Alpine reserves destroy() as a teardown hook. A delete action
            // with that name runs when navigation removes this component.
            async deleteCatalog() {
                if (!confirm(@json(__('agents.catalog.toasts.delete_confirm')))) return;

                // deleteCatalog() is about to delete every product (and so every
                // photo) for this agent in one bulk pass. Sending each
                // pending removal's own DELETE first would just race it —
                // and arrive after its product/image row is already gone,
                // surfacing a confusing error for a removal that is about to
                // succeed anyway. Cancel their timers and drop them locally
                // instead; nothing further needs to be sent.
                this.photoRemovals.forEach((removal) => clearTimeout(removal.timer));
                this.photoRemovals = [];

                this.deleting = true;

                try {
                    const res = await fetch(destroyUrl, { method: 'DELETE', headers: this.csrfHeaders() });
                    const { data, html } = await this.readJson(res);
                    if (!res.ok || html) {
                        console.error('Catalog delete failed', res.status, html ? '(non-JSON response)' : data);
                        this.showToast(html ? this.unexpectedResponseLabel : @json(__('agents.catalog.toasts.delete_failed')), 'error');
                        return;
                    }
                    this.dataset = data.dataset;
                    this.preview = data.preview ?? [];
                    this.pagination = data.pagination;
                    this.showToast(@json(__('agents.catalog.toasts.deleted')), 'success');
                } catch (e) {
                    this.reportNetworkFailure(e);
                } finally {
                    this.deleting = false;
                }
            },
        };
    }
</script>
@endpush
@endonce
