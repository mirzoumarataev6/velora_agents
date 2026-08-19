@extends('layouts.dashboard')

@section('title', __('agents.index.title'))

@section('content')
<div class="mx-auto max-w-6xl">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold">{{ __('agents.index.heading') }}</h1>
            <p class="mt-1 text-sm text-muted dark:text-night-muted">{{ __('agents.index.subheading') }}</p>
        </div>
        <a href="{{ route('agents.create') }}"
            class="flex items-center gap-2 rounded-full bg-brand px-4 py-2.5 text-sm font-semibold text-on-accent shadow-glow-ember transition-all hover:-translate-y-0.5 hover:bg-brand-hover">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
            {{ __('agents.index.create') }}
        </a>
    </div>

    @if ($agents->isEmpty())
        <div class="reveal mt-8 flex flex-col items-center justify-center rounded-3xl border border-dashed border-black/10 bg-white/50 px-6 py-16 text-center dark:border-white/10 dark:bg-white/5">
            <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-brand/10 text-brand">
                <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 12a4 4 0 100-8 4 4 0 000 8zm0 0c-4.4 0-8 2.24-8 5v2h16v-2c0-2.76-3.6-5-8-5z"/></svg>
            </div>
            <h2 class="mt-4 text-lg font-semibold">{{ __('agents.index.empty_title') }}</h2>
            <p class="mt-1.5 max-w-sm text-sm text-muted dark:text-night-muted">{{ __('agents.index.empty_description') }}</p>
            <a href="{{ route('agents.create') }}"
                class="mt-5 flex items-center gap-2 rounded-full bg-brand px-5 py-2.5 text-sm font-semibold text-on-accent transition-all hover:-translate-y-0.5 hover:bg-brand-hover">
                {{ __('agents.index.empty_cta') }}
            </a>
        </div>
    @else
        <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($agents as $agent)
                <a href="{{ route('agents.show', $agent) }}"
                    class="reveal group flex flex-col rounded-2xl border border-black/10 bg-white p-5 transition-all hover:-translate-y-0.5 hover:shadow-lg dark:border-white/10 dark:bg-white/5">
                    <div class="flex items-start justify-between gap-2">
                        <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-brand/10 text-lg font-semibold text-brand">
                            {{ mb_strtoupper(mb_substr($agent->name, 0, 1)) }}
                        </div>
                        <span class="flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium {{ $agent->status === 'active' ? 'bg-success/10 text-success dark:text-success-light' : 'bg-black/5 text-muted dark:bg-white/10 dark:text-night-muted' }}">
                            <span class="h-1.5 w-1.5 rounded-full {{ $agent->status === 'active' ? 'bg-success' : 'bg-muted dark:bg-white/40' }}"></span>
                            {{ $agent->status === 'active' ? __('agents.index.status_active') : __('agents.index.status_paused') }}
                        </span>
                    </div>

                    <h3 class="mt-4 text-base font-semibold group-hover:text-brand">{{ $agent->name }}</h3>
                    <p class="mt-1 line-clamp-2 text-sm text-muted dark:text-night-muted">{{ $agent->description ?: __('agents.index.no_description') }}</p>

                    <div class="mt-4 flex items-center gap-4 border-t border-black/5 pt-4 text-xs text-muted dark:border-white/10 dark:text-night-muted">
                        <span class="flex items-center gap-1.5">
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.746 0 3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
                            {{ $agent->knowledge_sources_count }}
                        </span>
                        <span class="flex items-center gap-1.5">
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                            {{ $agent->channels_count }}
                        </span>
                        <span class="flex items-center gap-1.5">
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8-1.06 0-2.08-.16-3.02-.46L3 21l1.5-4.5C3.55 15.1 3 13.6 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                            {{ $agent->conversations_count }}
                        </span>
                    </div>
                </a>
            @endforeach
        </div>
    @endif
</div>
@endsection
