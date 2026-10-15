@php
    $typeLabels = __('agents.knowledge.type_labels');
    $statusLabels = __('agents.knowledge.status_labels');
    $statusColors = [
        'pending' => 'bg-black/5 text-muted dark:bg-white/10 dark:text-night-muted',
        'processing' => 'bg-warning/10 text-warning dark:text-warning-light',
        'ready' => 'bg-success/10 text-success dark:text-success-light',
        'failed' => 'bg-danger/10 text-danger dark:text-danger-light',
    ];
@endphp

<div x-data="knowledgeTab({
        statusUrl: '{{ route('agents.knowledge.status', $agent) }}',
        filesUrl: '{{ route('agents.knowledge.files.store', $agent) }}',
        textUrl: '{{ route('agents.knowledge.text.store', $agent) }}',
        urlUrl: '{{ route('agents.knowledge.url.store', $agent) }}',
        deleteBaseUrl: '{{ route('agents.knowledge.destroy', ['agent' => $agent, 'knowledgeSource' => '__ID__']) }}',
        refetchBaseUrl: '{{ route('agents.knowledge.refetch', ['agent' => $agent, 'knowledgeSource' => '__ID__']) }}',
        initialSources: {{ Js::from($agent->knowledgeSources->map(fn ($s) => [
            'id' => $s->id,
            'name' => $s->name,
            'type' => $s->type,
            'status' => $s->status,
            'error_message' => $s->error_message,
            'chunks_count' => $s->chunks_count,
            'product_count' => $s->meta['product_count'] ?? null,
            'created_at' => $s->created_at->diffForHumans(),
        ])) }},
    {{-- Alpine calls this object's own init() itself; see the note in
         conversations/partials/inbox.blade.php. Spelling it out in x-init
         ran init() twice against a single destroy() — here that meant
         a second maybePoll() (idempotent only by luck of its `if (this.polling)` guard). --}}
    })" class="space-y-6">

    {{-- Upload zone --}}
    <div>
        <div
            @dragover.prevent="dragging = true"
            @dragleave.prevent="dragging = false"
            @drop.prevent="dragging = false; handleFiles($event.dataTransfer.files)"
            :class="dragging ? 'border-brand bg-azure-50' : 'border-black/15 dark:border-white/15'"
            class="flex flex-col items-center justify-center gap-2 rounded-2xl border-2 border-dashed px-6 py-10 text-center transition-colors">
            <svg x-show="!uploading" class="h-8 w-8 text-muted dark:text-night-muted/70" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16V4m0 0L7 9m5-5l5 5M4 16v3a2 2 0 002 2h12a2 2 0 002-2v-3"/></svg>
            <span x-show="uploading" class="spinner h-8 w-8"></span>
            <p class="text-sm font-medium" x-show="!uploading">{{ __('agents.knowledge.drop_text') }} <button type="button" @click="$refs.fileInput.click()" class="text-brand-strong hover:underline">{{ __('agents.knowledge.drop_browse') }}</button></p>
            <p class="text-sm font-medium" x-show="uploading">{{ __('agents.knowledge.uploading') }}</p>
            <p class="text-xs text-muted dark:text-night-muted">{{ __('agents.knowledge.file_hint') }}</p>
            <input type="file" name="files[]" multiple x-ref="fileInput" @change="handleFiles($event.target.files); $event.target.value = ''" accept=".pdf,.docx,.xlsx,.csv,.xml,.png,.jpg,.jpeg,.webp" class="hidden" />
        </div>

        <div class="mt-3 flex flex-wrap gap-2">
            <button type="button" @click="addTextOpen = true" class="flex items-center gap-1.5 rounded-full px-4 py-2 text-sm font-medium transition-all">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h10M4 18h7"/></svg>
                {{ __('agents.knowledge.add_text') }}
            </button>
            <button type="button" @click="addUrlOpen = true" class="flex items-center gap-1.5 rounded-full px-4 py-2 text-sm font-medium transition-all">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 010 5.656l-3 3a4 4 0 01-5.656-5.656l1.5-1.5M10.172 13.828a4 4 0 010-5.656l3-3a4 4 0 015.656 5.656l-1.5 1.5"/></svg>
                {{ __('agents.knowledge.add_link') }}
            </button>
        </div>
    </div>

    {{-- Sources table --}}
    <div class="overflow-hidden rounded-2xl">
        <template x-if="sources.length === 0">
            <div class="empty-state">
                <span class="empty-state-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.746 0 3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
                </span>
                <p class="empty-state-title">{{ __('agents.knowledge.empty_title') }}</p>
                <p class="empty-state-text">{{ __('agents.knowledge.empty_description') }}</p>
            </div>
        </template>

        <table class="table-flat" x-show="sources.length > 0">
            <thead>
                <tr>
                    <th class="px-4 py-3 font-medium">{{ __('agents.knowledge.col_source') }}</th>
                    <th class="px-4 py-3 font-medium">{{ __('agents.knowledge.col_type') }}</th>
                    <th class="px-4 py-3 font-medium">{{ __('agents.knowledge.col_status') }}</th>
                    <th class="px-4 py-3 font-medium">{{ __('agents.knowledge.col_chunks') }}</th>
                    <th class="px-4 py-3 font-medium">{{ __('agents.knowledge.col_added') }}</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody>
                <template x-for="source in sources" :key="source.id">
                    <tr class="border-t border-hairline dark:border-white/10">
                        <td class="max-w-xs truncate px-4 py-3 font-medium" x-text="source.name"></td>
                        <td class="px-4 py-3 text-muted dark:text-night-muted" x-text="typeLabels[source.type] || source.type"></td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium" :class="statusColors[source.status]">
                                <svg x-show="source.status === 'processing' || source.status === 'pending'" class="spinner h-3 w-3" aria-hidden="true"></svg>
                                <span x-text="statusLabels[source.status] || source.status"></span>
                                <span x-show="source.status === 'ready' && source.product_count" x-text="productsSuffix.replace(':count', source.product_count)"></span>
                            </span>
                            <p x-show="source.status === 'failed' && source.error_message" x-text="source.error_message" class="mt-1 max-w-xs text-xs text-danger"></p>
                        </td>
                        <td class="px-4 py-3 text-muted dark:text-night-muted" x-text="source.chunks_count"></td>
                        <td class="px-4 py-3 text-muted dark:text-night-muted" x-text="source.created_at"></td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-1">
                                <button type="button" x-show="source.type === 'url'" @click="refetchSource(source)"
                                    :disabled="source._refetching || source.status === 'pending' || source.status === 'processing'"
                                    :title="@js(__('agents.knowledge.refetch'))" :aria-label="@js(__('agents.knowledge.refetch'))"
                                    class="text-muted transition-colors hover:text-brand-strong disabled:opacity-40 dark:text-night-muted/70">
                                    <svg class="h-4 w-4" :class="source._refetching && 'animate-spin'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                </button>
                                <button type="button" @click="deleteSource(source)" :disabled="source._deleting" class="text-muted transition-colors hover:text-danger disabled:opacity-40 dark:text-night-muted/70">
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                </button>
                            </div>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>

    {{-- Add text modal --}}
    <div x-show="addTextOpen" x-cloak x-transition.opacity.duration.200ms class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4" @keydown.escape.window="addTextOpen = false">
        <div x-show="addTextOpen" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100" @click.outside="addTextOpen = false" class="w-full max-w-lg rounded-2xl bg-white p-6 dark:bg-[var(--color-night-card)]">
            <h3 class="text-lg font-semibold">{{ __('agents.knowledge.modal_text_title') }}</h3>
            <form @submit.prevent="submitText()" class="mt-4 space-y-3">
                <div>
                    <label class="text-sm font-medium">{{ __('agents.knowledge.name_label') }}</label>
                    <input type="text" x-model="textForm.name" required placeholder="{{ __('agents.knowledge.name_placeholder') }}"
 class="field mt-1 w-full px-4 py-2.5 text-sm" />
                    <p x-show="textErrors.name" x-text="textErrors.name?.[0]" class="mt-1 text-xs text-danger"></p>
                </div>
                <div>
                    <label class="text-sm font-medium">{{ __('agents.knowledge.content_label') }}</label>
                    <textarea x-model="textForm.content" required rows="8" placeholder="{{ __('agents.knowledge.content_placeholder') }}"
 class="field mt-1 w-full px-4 py-2.5 text-sm"></textarea>
                    <p x-show="textErrors.content" x-text="textErrors.content?.[0]" class="mt-1 text-xs text-danger"></p>
                </div>
                <div class="flex justify-end gap-2 pt-1">
                    <button type="button" @click="addTextOpen = false" class="rounded-full px-4 py-2 text-sm font-semibold">{{ __('agents.knowledge.cancel') }}</button>
                    <button type="submit" :disabled="textSubmitting" class="btn btn-primary disabled:opacity-60">
                        <span x-show="!textSubmitting">{{ __('agents.knowledge.add') }}</span>
                        <span x-show="textSubmitting" class="spinner"></span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- Add URL modal --}}
    <div x-show="addUrlOpen" x-cloak x-transition.opacity.duration.200ms class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4" @keydown.escape.window="addUrlOpen = false">
        <div x-show="addUrlOpen" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100" @click.outside="addUrlOpen = false" class="w-full max-w-lg rounded-2xl bg-white p-6 dark:bg-[var(--color-night-card)]">
            <h3 class="text-lg font-semibold">{{ __('agents.knowledge.modal_url_title') }}</h3>
            <form @submit.prevent="submitUrl()" class="mt-4 space-y-3">
                <div>
                    <label class="text-sm font-medium">{{ __('agents.knowledge.url_label') }}</label>
                    <input type="url" x-model="urlForm.url" required placeholder="{{ __('agents.knowledge.url_placeholder') }}"
 class="field mt-1 w-full px-4 py-2.5 text-sm" />
                    <p x-show="urlErrors.url" x-text="urlErrors.url?.[0]" class="mt-1 text-xs text-danger"></p>
                </div>
                <div class="flex justify-end gap-2 pt-1">
                    <button type="button" @click="addUrlOpen = false" class="rounded-full px-4 py-2 text-sm font-semibold">{{ __('agents.knowledge.cancel') }}</button>
                    <button type="submit" :disabled="urlSubmitting" class="btn btn-primary disabled:opacity-60">
                        <span x-show="!urlSubmitting">{{ __('agents.knowledge.add') }}</span>
                        <span x-show="urlSubmitting" class="spinner"></span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

@once
@push('scripts')
<script>
    function knowledgeTab({ statusUrl, filesUrl, textUrl, urlUrl, deleteBaseUrl, refetchBaseUrl, initialSources }) {
        return {
            sources: initialSources,
            dragging: false,
            uploading: false,
            addTextOpen: false,
            addUrlOpen: false,
            textForm: { name: '', content: '' },
            urlForm: { url: '' },
            textErrors: {},
            urlErrors: {},
            textSubmitting: false,
            urlSubmitting: false,
            polling: null,
            typeLabels: @json($typeLabels),
            statusLabels: @json($statusLabels),
            statusColors: @json($statusColors),
            productsSuffix: @json(__('agents.knowledge.products_suffix')),
            init() {
                this.maybePoll();
            },
            csrfHeaders(extra = {}) {
                return {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    ...extra,
                };
            },
            async handleFiles(fileList) {
                if (!fileList || fileList.length === 0) return;
                this.uploading = true;
                const formData = new FormData();
                for (const file of fileList) formData.append('files[]', file);

                try {
                    const res = await fetch(filesUrl, { method: 'POST', headers: this.csrfHeaders(), body: formData });
                    if (!res.ok) {
                        window.showToast?.(@json(__('agents.knowledge.toasts.upload_failed')), 'error');
                        return;
                    }
                    const data = await res.json();
                    this.sources.unshift(...data.sources);
                    this.maybePoll();
                    window.showToast?.(@json(__('agents.knowledge.toasts.files_added')), 'success');
                } catch (e) {
                    window.showToast?.(@json(__('agents.knowledge.toasts.network_error')), 'error');
                } finally {
                    this.uploading = false;
                }
            },
            async submitText() {
                this.textSubmitting = true;
                this.textErrors = {};
                try {
                    const res = await fetch(textUrl, {
                        method: 'POST',
                        headers: this.csrfHeaders({ 'Content-Type': 'application/json' }),
                        body: JSON.stringify(this.textForm),
                    });
                    if (res.status === 422) {
                        const data = await res.json();
                        this.textErrors = data.errors || {};
                        return;
                    }
                    if (!res.ok) {
                        window.showToast?.(@json(__('agents.knowledge.toasts.text_failed')), 'error');
                        return;
                    }
                    const data = await res.json();
                    this.sources.unshift(data.source);
                    this.addTextOpen = false;
                    this.textForm = { name: '', content: '' };
                    this.maybePoll();
                    window.showToast?.(@json(__('agents.knowledge.toasts.text_added')), 'success');
                } catch (e) {
                    window.showToast?.(@json(__('agents.knowledge.toasts.network_error')), 'error');
                } finally {
                    this.textSubmitting = false;
                }
            },
            async submitUrl() {
                this.urlSubmitting = true;
                this.urlErrors = {};
                try {
                    const res = await fetch(urlUrl, {
                        method: 'POST',
                        headers: this.csrfHeaders({ 'Content-Type': 'application/json' }),
                        body: JSON.stringify(this.urlForm),
                    });
                    if (res.status === 422) {
                        const data = await res.json();
                        this.urlErrors = data.errors || {};
                        return;
                    }
                    if (!res.ok) {
                        window.showToast?.(@json(__('agents.knowledge.toasts.link_failed')), 'error');
                        return;
                    }
                    const data = await res.json();
                    this.sources.unshift(data.source);
                    this.addUrlOpen = false;
                    this.urlForm = { url: '' };
                    this.maybePoll();
                    window.showToast?.(@json(__('agents.knowledge.toasts.link_added')), 'success');
                } catch (e) {
                    window.showToast?.(@json(__('agents.knowledge.toasts.network_error')), 'error');
                } finally {
                    this.urlSubmitting = false;
                }
            },
            async refetchSource(source) {
                source._refetching = true;
                try {
                    const res = await fetch(refetchBaseUrl.replace('__ID__', source.id), {
                        method: 'POST',
                        headers: { ...this.csrfHeaders(), Accept: 'application/json' },
                    });
                    if (!res.ok) {
                        window.showToast?.(@json(__('agents.knowledge.toasts.refetch_failed')), 'error');
                        source._refetching = false;
                        return;
                    }
                    const data = await res.json();
                    // Replace the row with the freshly-queued source and resume polling.
                    this.sources = this.sources.map((s) => (s.id === source.id ? { ...data.source, _refetching: false } : s));
                    window.showToast?.(@json(__('agents.knowledge.toasts.refetch_queued')), 'success');
                    this.maybePoll();
                } catch (e) {
                    window.showToast?.(@json(__('agents.knowledge.toasts.network_error')), 'error');
                    source._refetching = false;
                }
            },
            async deleteSource(source) {
                if (!confirm(@json(__('agents.knowledge.toasts.delete_confirm')).replace(':name', source.name))) return;
                source._deleting = true;
                try {
                    const res = await fetch(deleteBaseUrl.replace('__ID__', source.id), {
                        method: 'DELETE',
                        headers: this.csrfHeaders(),
                    });
                    if (!res.ok) {
                        window.showToast?.(@json(__('agents.knowledge.toasts.delete_failed')), 'error');
                        source._deleting = false;
                        return;
                    }
                    this.sources = this.sources.filter((s) => s.id !== source.id);
                    window.showToast?.(@json(__('agents.knowledge.toasts.deleted')), 'success');
                } catch (e) {
                    window.showToast?.(@json(__('agents.knowledge.toasts.network_error')), 'error');
                    source._deleting = false;
                }
            },
            hasPending() {
                return this.sources.some((s) => s.status === 'pending' || s.status === 'processing');
            },
            maybePoll() {
                if (this.polling) return;
                if (!this.hasPending()) return;
                this.polling = setInterval(async () => {
                    try {
                        const res = await fetch(statusUrl, { headers: { Accept: 'application/json' } });
                        const data = await res.json();
                        this.sources = data.sources;
                    } catch (e) { /* silent — retried on next tick */ }
                    if (!this.hasPending()) {
                        clearInterval(this.polling);
                        this.polling = null;
                    }
                }, 3000);
            },
        };
    }
</script>
@endpush
@endonce
