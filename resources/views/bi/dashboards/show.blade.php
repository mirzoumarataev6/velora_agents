@extends('layouts.dashboard')

@section('title', $dashboard->name)

@section('content')
{{--
    THE BOARD.

    Rendered complete on the server — every card's numbers are already in the
    HTML when the page arrives. That is unusual for a dashboard product, and
    deliberate: the results are cached by QueryRunner on a key that already
    carries the dataset version, so re-opening a board you looked at this
    morning is a handful of cache reads and no queries at all. Shipping that as
    HTML costs nothing and means the board is whole the moment it appears,
    instead of eight cards popping in over two seconds.

    Gridstack then takes over the placement. Until it has booted, the cards are
    laid out by ordinary CSS in grid order (see `.grid-stack:not(.gs-initialized)`
    in app.css) — so a board with JavaScript still loading, or disabled
    entirely, is a readable stack of charts rather than a pile of absolutely
    positioned boxes on top of each other.
--}}
<div x-data="biBoard({
        layoutUrl: '{{ route('bi.dashboards.layout', $dashboard) }}',
        widgetDataUrlBase: '{{ route('bi.widgets.data', ['dashboard' => $dashboard, 'widget' => '__ID__']) }}',
        widgetDeleteUrlBase: '{{ route('bi.widgets.destroy', ['dashboard' => $dashboard, 'widget' => '__ID__']) }}',
        widgetUpdateUrlBase: '{{ route('bi.widgets.update', ['dashboard' => $dashboard, 'widget' => '__ID__']) }}',
        widgetStoreUrl: '{{ route('bi.widgets.store', $dashboard) }}',
        dashboardUpdateUrl: '{{ route('bi.dashboards.update', $dashboard) }}',
        dashboardName: @js($dashboard->name),
        mainDatasetId: {{ (int) ($dashboard->bi_dataset_id ?? $rendered->first()?->widget->bi_dataset_id ?? 0) }},
        dashboardFilters: {{ Js::from($dashboard->filters ?? []) }},
        boardUrl: '{{ route('bi.dashboards.show', $dashboard) }}',
        chatUrl: '{{ route('bi.chat.store', $dashboard) }}',
        undoUrlBase: '{{ route('bi.chat.undo', ['dashboard' => $dashboard, 'message' => '__ID__']) }}',
        chatHistory: {{ Js::from($chatMessages->map(fn ($m) => [
            'id' => $m->id, 'role' => $m->role, 'text' => $m->content,
            'effects' => collect($m->payload['effects'] ?? [])->map(fn ($e) => collect($e)->except(['html', 'undo'])->all())->all(),
            'undoable' => $m->role === 'assistant' && ! $m->undone_at && ! empty($m->payload['undo']),
        ])->values()) }},
        csrf: '{{ csrf_token() }}',
        datasets: {{ Js::from($datasets->map(fn ($d) => [
            'id' => $d->id,
            'name' => $d->name,
            'columns' => $d->columns->map(fn ($c) => [
                'name' => $c->physical_name,
                'label' => $c->displayName(),
                'type' => $c->type->value,
                'role' => $c->role->value,
                'numeric' => $c->type->isNumeric(),
                'temporal' => $c->type->isTemporal(),
                'distinct' => $c->distinct_count,
                'samples' => $c->sample_values ?? [],
            ])->values(),
        ])->values()) }},
        widgets: {{ Js::from($rendered->map(fn ($item) => [
            'id' => $item->widget->id,
            'title' => $item->widget->title,
            'type' => $item->widget->type->value,
            'dataset_id' => $item->widget->bi_dataset_id,
            'spec' => $item->widget->spec,
            'filters' => $item->widget->filters ?? [],
            'slot' => $item->widget->slot(),
        ])->values()) }},
        labels: {{ Js::from([
            'deleteWidget' => __('bi.widgets.delete_confirm'),
            'saved' => __('bi.widgets.saved'),
            'failed' => __('bi.errors.internal_error'),
            'invalidDate' => __('bi.dashboards.invalid_date'),
            'invalidRange' => __('bi.dashboards.invalid_date_range'),
        ]) }},
        dateFormat: @js(app()->getLocale() === 'en' ? 'YYYY-MM-DD' : 'DD.MM.YYYY'),
    })" x-init="init()">

    <div class="page-header">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="page-title min-w-0 break-words">{{ $dashboard->name }}</h1>
                @if ($dashboard->is_default)
                    <span class="badge-info">{{ __('bi.dashboards.default_badge') }}</span>
                @endif
            </div>
            @if ($dashboard->description)
                <p class="page-sub">{{ $dashboard->description }}</p>
            @else
                <p class="page-sub">{{ $dashboard->source?->name ?? $dashboard->mainDataset?->name ?? __('bi.title') }} · {{ __('bi.analysis.updated') }} {{ $dashboard->mainDataset?->materialized_at?->diffForHumans() ?? $dashboard->updated_at?->diffForHumans() }}</p>
            @endif
        </div>

        <div class="page-actions flex items-center gap-2">
            {{-- The board switcher. A <select> rather than a dropdown of links
                 because it is a navigation control with one job and a native
                 select is keyboard- and screen-reader-correct for free. --}}
            @if ($dashboards->count() > 1)
                <select class="field !w-44 !py-1.5 text-sm"
                    onchange="if (this.value) window.location.href = this.value">
                    @foreach ($dashboards as $board)
                        <option value="{{ route('bi.dashboards.show', $board) }}"
                            @selected($board->is($dashboard))>{{ $board->name }}</option>
                    @endforeach
                </select>
            @endif

            {{-- `fresh=1` skips the cache for every card at once. A link rather
                 than a fetch, so the result is a normal, bookmark-free page
                 load with the new numbers already rendered. --}}
            <button type="button" class="btn-ghost text-sm" @click="refreshBoard(true)" :disabled="refreshing">
                <span x-show="!refreshing" class="hidden sm:inline">{{ __('bi.dashboards.refresh') }}</span>
                <span x-show="!refreshing" class="sm:hidden">{{ __('bi.dashboards.refresh_short') }}</span>
                <span x-show="refreshing" x-cloak>{{ __('bi.analysis.processing') }}</span>
            </button>

            <button type="button" class="btn btn-primary text-sm" @click="newWidget()">
                {{ __('bi.widgets.add') }}
            </button>
            <button type="button" x-ref="chatToggle" data-qa="chat-toggle" class="btn btn-ghost text-sm" @click="chatOpen=true; $nextTick(() => $refs.chatClose?.focus())" :aria-expanded="chatOpen">{{ __('bi.chat.title') }}</button>
        </div>
    </div>

    @include('bi.partials.subnav')

    @if ($dashboard->source && $dashboard->source->datasets()->where('status', \App\Models\BiDataset::STATUS_READY)->count() > 1)
        <nav class="mt-4 flex gap-2 overflow-x-auto" aria-label="{{ __('bi.data.datasets') }}">
            @foreach ($dashboard->source->datasets()->where('status', \App\Models\BiDataset::STATUS_READY)->get() as $sheet)
                @php $sheetBoard = $dashboards->first(fn ($board) => $board->bi_source_id === $dashboard->bi_source_id && $board->bi_dataset_id === $sheet->id); @endphp
                @if ($sheetBoard)
                    <a href="{{ route('bi.dashboards.show', $sheetBoard) }}" class="tab {{ $sheet->id === $dashboard->bi_dataset_id ? 'active' : '' }}">{{ $sheet->sheet_name ?: $sheet->name }}</a>
                @else
                    <form method="POST" action="{{ route('bi.sources.analyze', $dashboard->source) }}">@csrf
                        <input type="hidden" name="dataset_id" value="{{ $sheet->id }}">
                        <button type="submit" class="tab">{{ $sheet->sheet_name ?: $sheet->name }}</button>
                    </form>
                @endif
            @endforeach
        </nav>
    @endif

    @include('bi.partials.dashboard-filters')

    <div class="mt-6 flex items-start gap-5">
    <div class="min-w-0 flex-1">
    @if ($rendered->isEmpty())
        <div class="card card-pad mt-6 flex flex-col items-center justify-center py-16 text-center">
            <h2 class="text-base font-medium text-ink dark:text-night-ink">{{ __('bi.dashboards.board_empty_title') }}</h2>
            <p class="mt-1 max-w-md text-sm text-muted dark:text-night-muted">{{ __('bi.dashboards.board_empty_body') }}</p>
            <button type="button" class="btn btn-primary mt-5" @click="newWidget()">{{ __('bi.widgets.add') }}</button>
        </div>
    @endif
        <div class="grid-stack" x-ref="grid">
            @foreach ($rendered as $item)
                @php $slot = $item->widget->slot(); @endphp
                {{-- The slot is written as gs-* attributes, which is how
                     gridstack reads an existing DOM rather than being handed a
                     JS array. That keeps the server the single source of the
                     layout: there is no second copy of these numbers to drift. --}}
                <div class="grid-stack-item" gs-id="{{ $item->widget->getKey() }}"
                    gs-x="{{ $slot['x'] }}" gs-y="{{ $slot['y'] }}"
                    gs-w="{{ $slot['w'] }}" gs-h="{{ $slot['h'] }}"
                    gs-min-w="{{ \App\Models\BiWidget::MIN_W }}" gs-min-h="{{ \App\Models\BiWidget::MIN_H }}">
                    <div class="grid-stack-item-content">
                        @include('bi.partials.widget-card', ['item' => $item])
                    </div>
                </div>
            @endforeach
        </div>
    </div>
    @include('bi.partials.analyst-chat')
    </div>

    @include('bi.partials.widget-editor')
</div>
@endsection

@push('scripts')
    @include('bi.partials.board-script')
    @if (session('status'))
        <script>document.addEventListener('DOMContentLoaded', () => window.showToast?.(@js(session('status')), 'success'));</script>
    @endif
@endpush
