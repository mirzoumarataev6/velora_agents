@extends('layouts.dashboard')

@section('title', __('agents.index.title'))

@section('content')
{{-- No width cap here: layouts/dashboard caps the content column once, at
     1600px, for every page. --}}
<div>
    {{-- Above the heading, not instead of it: onboarding sits inside the real
         product surface, and the agents grid below stays exactly as it was for
         anyone who has finished or skipped setup. --}}
    @if ($onboarding['visible'])
        {{-- Its own card above the checklist, not a strip inside it: the
             greeting is a separate thing that happens to arrive at the same
             time, and it leaves on its own without the checklist moving. --}}
        @include('partials.welcome-banner')
        @include('partials.onboarding-card', ['onboarding' => $onboarding])
    @endif

    {{-- The shared page-header shape: a count as the eyebrow, the title, the
         one-line explanation, and the primary action on the right. Every page
         in the product uses this, so moving between them never re-teaches the
         reader where to look. --}}
    <div class="page-header">
        <div>
            <p class="page-eyebrow">{{ trans_choice('agents.index.count', $agents->count(), ['count' => $agents->count()]) }}</p>
            <h1 class="page-title">{{ __('agents.index.heading') }}</h1>
            <p class="page-sub">{{ __('agents.index.subheading') }}</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('agents.create') }}" class="btn btn-primary">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                {{ __('agents.index.create') }}
            </a>
        </div>
    </div>

    @if ($agents->isEmpty())
        <div class="card empty-state reveal">
            <div class="icon-circle icon-circle-lg">
                <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 12a4 4 0 100-8 4 4 0 000 8zm0 0c-4.4 0-8 2.24-8 5v2h16v-2c0-2.76-3.6-5-8-5z"/></svg>
            </div>
            <h2 class="empty-state-title mt-4">{{ __('agents.index.empty_title') }}</h2>
            <p class="empty-state-text max-w-md">{{ __('agents.index.empty_description') }}</p>
            <a href="{{ route('agents.create') }}"
                class="btn btn-primary mt-5">
                {{ __('agents.index.empty_cta') }}
            </a>
        </div>
    @else
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
            @foreach ($agents as $agent)
                <a href="{{ route('agents.show', $agent) }}"
                    class="card card-lift reveal group flex flex-col p-5">
                    <div class="flex items-start justify-between gap-2">
                        <div class="icon-circle text-lg font-semibold">
                            {{ mb_strtoupper(mb_substr($agent->name, 0, 1)) }}
                        </div>
                        <span class="flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium {{ $agent->status === 'active' ? 'bg-success/10 text-success dark:text-success-light' : 'bg-black/5 text-muted dark:bg-white/10 dark:text-night-muted' }}">
                            <span class="h-1.5 w-1.5 rounded-full {{ $agent->status === 'active' ? 'bg-success' : 'bg-muted dark:bg-white/40' }}"></span>
                            {{ $agent->status === 'active' ? __('agents.index.status_active') : __('agents.index.status_paused') }}
                        </span>
                    </div>

                    <h3 class="mt-4 text-base font-semibold group-hover:text-brand-strong">{{ $agent->name }}</h3>
                    <p class="mt-1 line-clamp-2 text-sm text-muted dark:text-night-muted">{{ $agent->description ?: __('agents.index.no_description') }}</p>

                    {{-- `mt-auto` floors this row, so a card with a one-line
                         description and one with three both end level in the
                         grid. Each count is labelled: three bare numbers
                         behind three small icons were a puzzle, not a stat. --}}
                    <div class="rule mt-auto flex flex-wrap items-center gap-x-3 gap-y-1.5 pt-4 text-xs text-muted dark:text-night-muted">
                        <span class="flex items-center gap-1.5">
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.746 0 3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
                            <span class="tnum">{{ $agent->knowledge_sources_count }}</span>
                            <span>{{ __('agents.index.stat_knowledge') }}</span>
                        </span>
                        <span class="flex items-center gap-1.5">
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                            <span class="tnum">{{ $agent->channels_count }}</span>
                            <span>{{ __('agents.index.stat_channels') }}</span>
                        </span>
                        <span class="flex items-center gap-1.5">
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8-1.06 0-2.08-.16-3.02-.46L3 21l1.5-4.5C3.55 15.1 3 13.6 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                            <span class="tnum">{{ $agent->conversations_count }}</span>
                            <span>{{ __('agents.index.stat_conversations') }}</span>
                        </span>
                    </div>
                </a>
            @endforeach
        </div>
    @endif
</div>
@endsection
