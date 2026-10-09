{{-- Upload bytes and ingest status are separate: a full progress bar means the
     server has received the file, not that the background job has finished. --}}
<section class="mt-6 flex flex-col" x-data="analysisHome({
    uploadUrl: @js(route('bi.sources.store')),
    statusUrl: @js(route('bi.sources.status')),
    csrf: @js(csrf_token()),
    pending: {{ $sources->contains(fn ($s) => ! in_array($s->status, ['ready', 'failed'], true) || $s->datasets->contains(fn ($d) => ! in_array($d->status, ['ready', 'failed'], true))) ? 'true' : 'false' }},
    errorLabel: @js(__('bi.errors.internal_error')),
})" x-init="init()">
    <div class="grid gap-5 lg:grid-cols-2 {{ $sources->isNotEmpty() ? 'order-2 mt-6' : '' }}">
        <div class="card card-lift rounded-2xl {{ $sources->isNotEmpty() ? 'p-5' : 'p-6 sm:p-8' }}" @dragover.prevent="dragging = true"
            @dragleave.prevent="dragging = false" @drop.prevent="dragging = false; upload($event.dataTransfer.files[0])"
            :class="dragging && 'ring-2 ring-brand'">
            <div class="icon-circle mb-3"><svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M4 5h16v14H4zM4 10h16M4 15h16M10 10v9M16 10v9"/></svg></div>
            <h2 class="text-lg font-semibold">{{ __('bi.data.upload') }}</h2>
            <p class="mt-2 max-w-sm text-sm leading-relaxed text-muted dark:text-night-muted">{{ __('bi.analysis.upload_hint') }}</p>
            <button type="button" class="btn btn-primary mt-4" @click="$refs.file.click()">{{ __('bi.data.upload') }}</button>
            <input type="file" x-ref="file" class="hidden" accept=".csv,.tsv,.txt,.xlsx,.xls,.ods,.json,.ndjson,.jsonl" @change="upload($event.target.files[0]); $event.target.value = ''">
            <div x-show="uploading" x-cloak class="mt-5" aria-live="polite"><p class="text-xs tabular-nums" x-text="`${progress}%`"></p><div class="mt-2 h-2 overflow-hidden rounded-full bg-sunk dark:bg-night-raised"><div class="h-full bg-brand transition-all" :style="`width:${progress}%`"></div></div></div>
            <p x-show="error" x-cloak class="mt-3 text-sm text-danger" role="alert" x-text="error"></p>
        </div>
        <a href="{{ route('bi.sources.index', ['connect' => 1]) }}" class="card card-lift rounded-2xl {{ $sources->isNotEmpty() ? 'p-5' : 'p-6 sm:p-8' }}">
            <div class="icon-circle mb-3"><svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M4 7c0-1.7 3.6-3 8-3s8 1.3 8 3-3.6 3-8 3-8-1.3-8-3zm0 0v10c0 1.7 3.6 3 8 3s8-1.3 8-3V7M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/></svg></div>
            <h2 class="text-lg font-semibold">{{ __('bi.data.connect_db') }}</h2>
            <p class="mt-2 max-w-sm text-sm leading-relaxed text-muted dark:text-night-muted">{{ __('bi.analysis.connect_hint') }}</p>
            <span class="btn btn-ghost mt-4">{{ __('bi.data.connect_db') }} →</span>
        </a>
    </div>
    @if ($sources->isNotEmpty())
        <div class="order-1 space-y-3">
            <h2 class="text-lg font-semibold">{{ __('bi.analysis.your_sources') }}</h2>
            @foreach ($sources as $source)
                @php $ready = $source->datasets->where('status', \App\Models\BiDataset::STATUS_READY)->sortByDesc('row_count')->first(); @endphp
                <div class="card flex flex-wrap items-center gap-4 px-5 py-4" data-analysis-source="{{ $source->id }}">
                    <div class="min-w-0 flex-1"><p class="truncate font-semibold">{{ $source->name }}</p><p class="mt-1 text-xs text-muted dark:text-night-muted" data-source-status>{{ __('bi.status.'.$source->status) }}@if ($ready) · {{ number_format($ready->row_count, 0, '.', ' ') }} {{ __('bi.data.rows') }}@endif</p></div>
                    <form method="POST" action="{{ route('bi.sources.analyze', $source) }}" data-analysis-form @if (! $ready) hidden @endif>@csrf<button class="btn btn-primary btn-sm" type="submit">{{ __('bi.analysis.make') }}</button></form>
                    <span class="badge badge-warning" data-analysis-pending @if ($ready || $source->status === 'failed') hidden @endif>{{ __('bi.analysis.processing') }}</span>
                    @if ($source->status === 'failed')<span class="badge badge-danger">{{ __('bi.status.failed') }}</span>@endif
                </div>
            @endforeach
        </div>
    @endif
</section>
@push('scripts')
<script>
function analysisHome(config) {
    return {
        uploading: false, dragging: false, progress: 0, error: '',
        init() { if (config.pending) this.poll(); },
        upload(file) {
            if (!file || this.uploading) return;
            this.uploading = true; this.progress = 0; this.error = '';
            const form = new FormData(); form.append('file', file);
            const xhr = new XMLHttpRequest(); xhr.open('POST', config.uploadUrl);
            xhr.setRequestHeader('Accept', 'application/json'); xhr.setRequestHeader('X-CSRF-TOKEN', config.csrf);
            xhr.upload.onprogress = e => { if (e.lengthComputable) this.progress = Math.round(100 * e.loaded / e.total); };
            xhr.onload = () => {
                this.uploading = false;
                if (xhr.status === 201) { window.location.reload(); return; }
                try { const body = JSON.parse(xhr.responseText); this.error = Object.values(body.errors || {}).flat()[0] || body.message || config.errorLabel; }
                catch (_) { this.error = config.errorLabel; }
            };
            xhr.onerror = () => { this.uploading = false; this.error = config.errorLabel; };
            xhr.send(form);
        },
        async poll() {
            try {
                const response = await fetch(config.statusUrl, {headers: {Accept: 'application/json'}});
                if (!response.ok) throw new Error('status');
                let pending = false;
                for (const source of (await response.json()).sources || []) {
                    const card = this.$el.querySelector(`[data-analysis-source="${source.id}"]`);
                    if (!card) continue;
                    const ready = source.datasets.find(d => d.status === 'ready');
                    card.querySelector('[data-source-status]').textContent = ready ? `${source.status_label} · ${new Intl.NumberFormat(document.documentElement.lang).format(ready.row_count)} {{ __('bi.data.rows') }}` : source.status_label;
                    card.querySelector('[data-analysis-form]').hidden = !ready;
                    card.querySelector('[data-analysis-pending]').hidden = !!ready || source.status === 'failed';
                    pending ||= !['ready', 'failed'].includes(source.status) || source.datasets.some(d => !['ready', 'failed'].includes(d.status));
                }
                if (pending) setTimeout(() => this.poll(), 2500);
            } catch (_) { setTimeout(() => this.poll(), 5000); }
        },
    };
}
</script>
@endpush
