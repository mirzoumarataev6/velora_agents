@extends('layouts.dashboard')

@section('title', __('bi.data.title'))

@php
    $maxRows = (int) config('agent.bi.ingest.max_rows');
@endphp

@section('content')
{{--
    THE DATA PANEL. Two levels, and which one the owner clicks is the whole
    information architecture of this page:

      a SOURCE is a thing they gave us — a file, a connection. It has a status and
      a delete button and otherwise nothing to say.
      a DATASET is a thing they can ask questions of. It has rows, columns, types
      that may be wrong, and a drawer.

    So the source is a container that stays quiet and the dataset rows are the
    targets. Getting this the other way round — one card per file, with the sheets
    hidden inside — is how a four-sheet workbook ends up looking like one table.
--}}
<div class="flex flex-col" x-data="biSources({
        statusUrl: '{{ route('bi.sources.status') }}',
        storeUrl: '{{ route('bi.sources.store') }}',
        deleteUrlBase: '{{ route('bi.sources.destroy', ['source' => '__ID__']) }}',
        retryUrlBase: '{{ route('bi.sources.retry', ['source' => '__ID__']) }}',
        datasetUrlBase: '{{ route('bi.datasets.show', ['dataset' => '__ID__']) }}',
        columnsUrlBase: '{{ route('bi.datasets.columns', ['dataset' => '__ID__']) }}',
        rebuildUrlBase: '{{ route('bi.datasets.rematerialize', ['dataset' => '__ID__']) }}',
        datasetDeleteUrlBase: '{{ route('bi.datasets.destroy', ['dataset' => '__ID__']) }}',
        connectionsUrl: '{{ route('bi.connections.index') }}',
        connectionTablesUrlBase: '{{ route('bi.connections.tables', ['connection' => '__ID__']) }}',
        connectionTestUrl: '{{ route('bi.connections.test') }}',
        connectionRetestUrlBase: '{{ route('bi.connections.retest', ['connection' => '__ID__']) }}',
        connectionStoreUrl: '{{ route('bi.connections.store') }}',
        connectionUpdateUrlBase: '{{ route('bi.connections.update', ['connection' => '__ID__']) }}',
        connectionDeleteUrlBase: '{{ route('bi.connections.destroy', ['connection' => '__ID__']) }}',
        connectSourceUrl: '{{ route('bi.connections.sources') }}',
        csrf: '{{ csrf_token() }}',
        pending: {{ $hasPendingWork ? 'true' : 'false' }},
        labels: {{ Js::from([
            'deleteConfirm' => __('bi.data.delete_confirm'),
            'deleted' => __('bi.data.deleted'),
            'datasetDeleteConfirm' => __('bi.schema.delete_dataset_confirm'),
            'datasetDeleted' => __('bi.schema.deleted_dataset'),
            'saved' => __('bi.schema.saved'),
            'refreshed' => __('bi.schema.refreshed'),
            'registered' => __('bi.connect.registered'),
            'tested' => __('bi.connect.tested'),
            'connectionCreated' => __('bi.connect.created'),
            'connectionUpdated' => __('bi.connect.updated'),
            'connectionDeleted' => __('bi.connect.deleted'),
            'connectionDeleteConfirm' => __('bi.connect.delete_confirm'),
            'notVerified' => __('bi.connect.not_verified'),
            'failed' => __('bi.errors.internal_error'),
            'queued' => __('bi.data.queued'),
            'yes' => __('bi.schema.yes'),
            'no' => __('bi.schema.no'),
        ]) }},
    })" x-init="init()">

    <div class="page-header">
        <div>
            <h1 class="page-title" data-i18n="bi.data.title">{{ __('bi.data.title') }}</h1>
            <p class="page-sub" data-i18n="bi.data.subtitle">{{ __('bi.data.subtitle') }}</p>
        </div>
        @if ($sources->isNotEmpty())
            <div class="page-actions">
                <span class="text-xs tabular-nums text-muted dark:text-night-muted">
                    {{ trans_choice('bi.data.total', $sources->count(), ['count' => $sources->count()]) }}
                </span>
            </div>
        @endif
    </div>

    @include('bi.partials.subnav')

    {{-- THE TWO WAYS IN, side by side rather than stacked behind a tab.

         An owner arrives here with either a spreadsheet or a database, never
         wondering which; putting one of them behind a tab would make half the
         audience hunt for it. The upload card gets the width because it is the
         common case, and the connection card is a peer rather than a footnote.

         DELIBERATELY NOT A DASHED DROPZONE, and that is a correction rather
         than a style preference. This card used to be
         `rounded-2xl border-2 border-dashed` with the copy "Перетащите файлы
         сюда или выберите на компьютере" — which is, to the pixel and to the
         word, the agent knowledge base's uploader in
         agents/partials/knowledge-tab.blade.php. Two features that do
         completely different things with a file looked like one feature, and
         owners read this screen as "what the AI knows".

         So this one is a solid card led by a TABLE icon and a primary button;
         the knowledge uploader keeps the dashed outline and its document icon.
         Drag-and-drop still works here — it is just no longer the thing the
         eye lands on, and the copy says "таблица" where the other says
         "файлы". --}}
    <div class="mt-4 grid gap-3 lg:grid-cols-[1.6fr_1fr] {{ $sources->isNotEmpty() ? 'order-2' : '' }}">
        <div
            @dragover.prevent="dragging = true"
            @dragenter.prevent="dragging = true"
            @dragleave.prevent="dragging = false"
            @drop.prevent="dragging = false; pick($event.dataTransfer.files[0])"
            :class="dragging ? 'ring-2 ring-brand bg-brand-circle dark:bg-night-raised' : ''"
            class="card flex flex-col items-start gap-1.5 rounded-2xl px-5 py-5 text-left transition-colors">

            <template x-if="!uploading">
                <div class="flex w-full flex-col items-start gap-1.5">
                    {{-- A TABLE icon, not a document-with-an-arrow. The thing
                         being uploaded here is rows and columns to query; the
                         knowledge base uploads documents to read. --}}
                    <div class="icon-circle mb-1">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 5h16v14H4zM4 10h16M4 15h16M9.5 10v9M15 10v9"/></svg>
                    </div>
                    <p class="text-sm font-semibold" data-i18n="bi.data.drop_title">{{ __('bi.data.drop_title') }}</p>
                    <p class="text-xs leading-relaxed text-muted dark:text-night-muted">
                        {{ __('bi.data.formats') }} · {{ __('bi.data.max_size', ['size' => $maxMegabytes]) }}
                    </p>
                    <button type="button" @click="$refs.file.click()" class="btn btn-primary btn-sm mt-2"
                        data-i18n="bi.data.upload">{{ __('bi.data.upload') }}</button>
                </div>
            </template>

            {{-- The progress bar is why the upload goes through XMLHttpRequest and
                 not fetch(): fetch has no upload-progress event at all, so a
                 100MB upload over a slow line would sit at "загрузка…" for two
                 minutes with nothing moving. A real percentage is the difference
                 between waiting and wondering whether it broke. --}}
            <template x-if="uploading">
                <div class="w-full">
                    <p class="text-sm font-semibold"
                        x-text="progress < 100
                            ? `${@js(__('bi.data.uploading'))} ${progress}%`
                            : @js(__('bi.data.processing_hint'))"></p>
                    <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-sunk dark:bg-white/10">
                        <div class="h-full rounded-full bg-brand transition-[width] duration-200"
                            :style="`width: ${Math.max(progress, 4)}%`"></div>
                    </div>
                    <p class="mt-1.5 truncate text-xs text-muted dark:text-night-muted" x-text="uploadName"></p>
                </div>
            </template>

            <input type="file" x-ref="file" class="hidden"
                accept=".csv,.tsv,.txt,.xlsx,.xls,.ods,.json,.ndjson,.jsonl"
                @change="pick($event.target.files[0]); $event.target.value = ''" />
        </div>

        <button type="button" @click="openConnect()"
            class="card card-lift flex flex-col items-start gap-1.5 px-5 py-5 text-left">
            <div class="icon-circle mb-1">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 7c0-1.657 3.582-3 8-3s8 1.343 8 3-3.582 3-8 3-8-1.343-8-3z"/><path stroke-linecap="round" stroke-linejoin="round" d="M20 7v10c0 1.657-3.582 3-8 3s-8-1.343-8-3V7"/><path stroke-linecap="round" stroke-linejoin="round" d="M20 12c0 1.657-3.582 3-8 3s-8-1.343-8-3"/></svg>
            </div>
            <p class="text-sm font-semibold" data-i18n="bi.data.connect_db">{{ __('bi.data.connect_db') }}</p>
            <p class="text-xs leading-relaxed text-muted dark:text-night-muted" data-i18n="bi.connect.subtitle">{{ __('bi.connect.subtitle') }}</p>
        </button>
    </div>

    <p x-show="uploadError" x-cloak x-text="uploadError" class="mt-2 text-xs text-danger"></p>
    @error('file')
        <p class="mt-2 text-xs text-danger">{{ $message }}</p>
    @enderror
    @error('tables')
        <p class="mt-2 text-xs text-danger">{{ $message }}</p>
    @enderror

    @if ($sources->isEmpty())
        <div class="card mt-3 flex flex-col items-center gap-2 px-6 py-14 text-center">
            <svg class="h-8 w-8 text-muted dark:text-night-muted/70" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
            <p class="text-sm font-semibold" data-i18n="bi.data.empty_title">{{ __('bi.data.empty_title') }}</p>
            <p class="max-w-sm text-xs leading-relaxed text-muted dark:text-night-muted" data-i18n="bi.data.empty_body">{{ __('bi.data.empty_body') }}</p>
        </div>
    @else
        <div class="order-1 mt-3 space-y-3">
            @foreach ($sources as $source)
                @php
                    $isConnection = $source->kind === \App\Models\BiSource::KIND_CONNECTION;
                    $settled = in_array($source->status, [\App\Models\BiSource::STATUS_READY, \App\Models\BiSource::STATUS_FAILED], true);
                @endphp

                <section class="card overflow-hidden" data-source="{{ $source->id }}">
                    {{-- The source header stays deliberately quiet: an icon that says
                         which KIND it is, the name, and the controls. Everything
                         interesting is a row below. --}}
                    <header class="flex flex-wrap items-center gap-3 px-5 py-4">
                        <span class="icon-circle icon-circle-sm shrink-0" aria-hidden="true">
                            @if ($isConnection)
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M4 7c0-1.657 3.582-3 8-3s8 1.343 8 3-3.582 3-8 3-8-1.343-8-3zM20 7v10c0 1.657-3.582 3-8 3s-8-1.343-8-3V7"/></svg>
                            @else
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 3H7a2 2 0 00-2 2v14a2 2 0 002 2h10a2 2 0 002-2V9l-6-6H9zM13 3v6h6"/></svg>
                            @endif
                        </span>

                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold">{{ $source->name }}</p>
                            <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-muted dark:text-night-muted">
                                <span>{{ $isConnection ? __('bi.data.live') : __('bi.data.file') }}</span>
                                @if ($source->bytes)
                                    <span aria-hidden="true">·</span>
                                    <span class="tabular-nums">{{ $source->bytes >= 1048576 ? round($source->bytes / 1048576, 1).' MB' : max(1, (int) round($source->bytes / 1024)).' KB' }}</span>
                                @endif
                                @if ($source->normalized_at)
                                    <span aria-hidden="true">·</span>
                                    <span>{{ __('bi.data.updated') }} {{ $source->normalized_at->diffForHumans() }}</span>
                                @endif
                            </p>
                        </div>

                        <div class="flex shrink-0 items-center gap-2">
                            @unless ($settled)
                                {{-- A spinner beside the status, not instead of it: the
                                     owner needs to know which STAGE is running, since
                                     "чтение файла" and "подготовка к запросам" fail for
                                     different reasons. --}}
                                <span class="spinner h-3.5 w-3.5" aria-hidden="true"></span>
                            @endunless
                            <span class="badge {{ match ($source->status) {
                                \App\Models\BiSource::STATUS_READY => 'badge-success',
                                \App\Models\BiSource::STATUS_FAILED => 'badge-danger',
                                default => 'badge-warning',
                            } }}">{{ __('bi.status.'.$source->status) }}</span>

                            @if ($source->status === \App\Models\BiSource::STATUS_FAILED)
                                <button type="button" @click="retry({{ $source->id }})" class="btn btn-ghost btn-sm">
                                    {{ __('bi.data.retry') }}
                                </button>
                            @endif

                            <button type="button" @click="destroySource({{ $source->id }})"
                                class="btn btn-ghost btn-sm text-muted hover:text-danger dark:text-night-muted"
                                aria-label="{{ __('bi.data.delete') }}" title="{{ __('bi.data.delete') }}">
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 7h12M9 7V5a1 1 0 011-1h4a1 1 0 011 1v2m-8 0v12a1 1 0 001 1h8a1 1 0 001-1V7"/></svg>
                            </button>
                        </div>
                    </header>

                    @if ($source->error_code)
                        <div class="mx-5 mb-4 rounded-xl bg-danger/8 px-3.5 py-3 text-xs">
                            <p class="font-medium text-danger">{{ $ingestErrors->sentence($source) }}</p>
                            @if ($detail = $ingestErrors->detail($source))
                                {{-- The technical remainder, for a support message. Never a
                                     stack trace, and never the only thing shown. --}}
                                <p class="mt-1 break-words text-muted dark:text-night-muted">{{ $detail }}</p>
                            @endif
                        </div>
                    @endif

                    @if ($source->datasets->isNotEmpty())
                        <div class="rule">
                            @foreach ($source->datasets as $dataset)
                                @php
                                    $ready = $dataset->status === \App\Models\BiDataset::STATUS_READY;
                                    $failed = $dataset->status === \App\Models\BiDataset::STATUS_FAILED;
                                @endphp
                                <button type="button" @click="openSchema({{ $dataset->id }})"
                                    class="group flex w-full flex-wrap items-center gap-x-3 gap-y-1 px-5 py-3 text-left transition-colors hover:bg-sunk/60 dark:hover:bg-white/[0.03] {{ ! $loop->first ? 'rule' : '' }}">
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm font-medium">
                                            {{ $dataset->name }}
                                            @if ($dataset->sheet_name && $dataset->sheet_name !== $dataset->name)
                                                <span class="font-normal text-muted dark:text-night-muted">· {{ __('bi.data.sheet') }} {{ $dataset->sheet_name }}</span>
                                            @endif
                                        </p>
                                        <p class="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-muted dark:text-night-muted">
                                            @if ($dataset->isExternal())
                                                <span class="font-mono">{{ $dataset->external_table }}</span>
                                                <span aria-hidden="true">·</span>
                                                <span class="tabular-nums">{{ $dataset->column_count }} {{ __('bi.data.columns') }}</span>
                                            @else
                                                <span class="tabular-nums">{{ number_format($dataset->row_count, 0, '.', ' ') }} {{ __('bi.data.rows') }}</span>
                                                <span aria-hidden="true">·</span>
                                                <span class="tabular-nums">{{ $dataset->column_count }} {{ __('bi.data.columns') }}</span>
                                            @endif
                                        </p>
                                    </div>

                                    {{-- The notes that stop a short table from being a
                                         mystery. Shown on the ROW, not only inside the
                                         drawer, because "these numbers are incomplete" is
                                         something the owner has to see without opening
                                         anything. --}}
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        @if ($dataset->truncated)
                                            <span class="badge badge-warning">{{ __('bi.notes.truncated', ['limit' => number_format($maxRows, 0, '.', ' ')]) }}</span>
                                        @endif
                                        @if ($dataset->skipped_rows > 0)
                                            <span class="badge badge-neutral">{{ __('bi.notes.skipped', ['count' => number_format($dataset->skipped_rows, 0, '.', ' ')]) }}</span>
                                        @endif
                                        <span class="badge {{ $ready ? 'badge-success' : ($failed ? 'badge-danger' : 'badge-warning') }}">
                                            {{ __('bi.status.'.$dataset->status) }}
                                        </span>
                                        <svg class="h-4 w-4 shrink-0 text-muted transition-transform group-hover:translate-x-0.5 dark:text-night-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                                    </div>

                                    @if ($dataset->error_code)
                                        <p class="w-full text-xs text-danger">{{ $ingestErrors->sentence($dataset, $source->extension) }}</p>
                                    @endif
                                </button>
                                @if ($ready && ! $dataset->isExternal())
                                    <form method="POST" action="{{ route('bi.sources.analyze', $source) }}" class="mb-3 ml-5">
                                        @csrf
                                        <button type="submit" class="btn btn-primary btn-sm">{{ __('bi.analysis.make') }}</button>
                                    </form>
                                @endif
                            @endforeach
                        </div>
                    @endif
                </section>
            @endforeach
        </div>
    @endif

    @include('bi.partials.schema-drawer')
    @include('bi.partials.connect-modal')
</div>
@endsection

@push('scripts')
    @include('bi.partials.sources-script', ['maxMegabytes' => $maxMegabytes])
@endpush
