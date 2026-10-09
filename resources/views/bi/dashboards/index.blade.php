@extends('layouts.dashboard')

@section('title', __('bi.title'))

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title" data-i18n="bi.title">{{ __('bi.title') }}</h1>
        <p class="page-sub" data-i18n="bi.dashboards.subtitle">{{ __('bi.dashboards.subtitle') }}</p>
    </div>
    <a href="{{ route('bi.sources.index') }}" class="btn btn-primary">+ {{ __('bi.analysis.new') }}</a>
</div>

@include('bi.partials.subnav')

{{-- A list of actual board metadata, not six invented bars pretending to be a
     preview of the owner's data. One board still earns the full work area. --}}
<div class="mt-6 space-y-3">
    @foreach ($dashboards as $board)
        <a href="{{ route('bi.dashboards.show', $board) }}" class="card card-lift group flex flex-col gap-5 p-5 sm:flex-row sm:items-center sm:justify-between sm:p-6">
            <div class="flex min-w-0 items-start gap-4">
                <span class="icon-circle icon-circle-lg shrink-0" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h7v7H4zM14 4h6v4h-6zM14 11h6v9h-6zM4 14h7v6H4z"/></svg>
                </span>
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="min-w-0 break-words text-lg font-semibold text-ink transition-colors group-hover:text-brand-strong dark:text-night-ink dark:group-hover:text-brand-light">{{ $board->name }}</h2>
                        @if ($board->is_default)
                            <span class="badge-info shrink-0">{{ __('bi.dashboards.default_badge') }}</span>
                        @endif
                    </div>
                    @if ($board->description)
                        <p class="mt-1 line-clamp-2 text-sm text-muted dark:text-night-muted">{{ $board->description }}</p>
                    @endif
                    <p class="mt-2 break-words text-xs text-muted dark:text-night-muted">{{ $board->source?->name ?? $board->mainDataset?->name ?? __('bi.dashboards.title') }}</p>
                </div>
            </div>
            <div class="flex shrink-0 items-center justify-between gap-5 border-t border-hairline pt-4 text-xs text-muted sm:border-l sm:border-t-0 sm:py-1 sm:pl-6 dark:border-white/10 dark:text-night-muted">
                <div class="space-y-1.5">
                    <p class="font-medium text-ink dark:text-night-ink">{{ trans_choice('bi.dashboards.widgets_count', $board->widgets_count, ['count' => $board->widgets_count]) }}</p>
                    <p class="tabular-nums">{{ $board->created_at?->format('d.m.Y') }}</p>
                </div>
                <svg class="h-5 w-5 text-brand-strong transition-transform group-hover:translate-x-1 dark:text-brand-light" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-6-6 6 6-6 6"/></svg>
            </div>
        </a>
    @endforeach
</div>
@endsection
