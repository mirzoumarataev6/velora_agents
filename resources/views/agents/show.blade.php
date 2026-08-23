@extends('layouts.dashboard')

@section('title', $agent->name.__('agents.show.title_suffix'))

@section('content')
{{-- The inbox tab wants the room the other tabs don't. --}}
<div class="mx-auto transition-[max-width] duration-200"
    :class="tab === 'conversations' ? 'max-w-7xl' : 'max-w-5xl'"
    x-data="{ tab: '{{ $tab }}' }"
    {{-- The open tab lives in ?tab= so it survives both a reload (the server
         reads it back and renders that tab first) and a language switch (the
         switcher rebuilds the URL from window.location.search, so the param
         rides along). replaceState rather than pushState: paging through tabs
         should not fill up the back button. --}}
    x-init="$watch('tab', (value) => {
        const url = new URL(window.location);
        url.searchParams.set('tab', value);
        window.history.replaceState({}, '', url);
    })">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-brand/10 text-lg font-semibold text-brand">
                {{ mb_strtoupper(mb_substr($agent->name, 0, 1)) }}
            </div>
            <div>
                <h1 class="text-xl font-semibold">{{ $agent->name }}</h1>
                <p class="text-sm text-muted dark:text-night-muted">{{ $agent->description ?: __('agents.show.no_description') }}</p>
            </div>
        </div>
        <a href="{{ route('agents.index') }}" class="flex items-center gap-1.5 text-sm font-medium text-muted hover:text-brand dark:text-night-muted">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
            {{ __('agents.show.back') }}
        </a>
    </div>

    {{-- Tab nav --}}
    <div class="mt-6 flex gap-1 overflow-x-auto border-b border-black/10 dark:border-white/10">
        @foreach (__('agents.show.tabs') as $key => $label)
            <button type="button" @click="tab = '{{ $key }}'"
                :class="tab === '{{ $key }}' ? 'border-brand text-brand' : 'border-transparent text-muted hover:text-ink dark:text-night-muted dark:hover:text-white'"
                class="shrink-0 border-b-2 px-4 py-3 text-sm font-medium transition-colors">
                <span data-i18n="agents.show.tabs.{{ $key }}">{{ $label }}</span>
            </button>
        @endforeach
    </div>

    <div class="mt-6">
        <div x-show="tab === 'knowledge'" x-cloak x-transition.opacity.duration.200ms>
            @include('agents.partials.knowledge-tab', ['agent' => $agent])
        </div>
        <div x-show="tab === 'catalog'" x-cloak x-transition.opacity.duration.200ms>
            @include('agents.partials.catalog-tab', ['agent' => $agent, 'catalog' => $catalog])
        </div>
        <div x-show="tab === 'channels'" x-cloak x-transition.opacity.duration.200ms>
            @include('agents.partials.channels-tab', ['agent' => $agent])
        </div>
        <div x-show="tab === 'playground'" x-cloak x-transition.opacity.duration.200ms>
            @include('agents.partials.playground-tab', ['agent' => $agent, 'playgroundConversation' => $playgroundConversation])
        </div>
        {{-- No x-show wrapper: the tab partial mounts itself with x-if so the
             inbox only boots when it is actually opened. --}}
        @include('agents.partials.conversations-tab', ['agent' => $agent])
        <div x-show="tab === 'settings'" x-cloak x-transition.opacity.duration.200ms>
            @include('agents.partials.settings-tab', ['agent' => $agent])
        </div>
    </div>
</div>
@endsection
