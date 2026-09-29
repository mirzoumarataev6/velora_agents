@extends('layouts.dashboard')

@section('title', $agent->name.__('agents.show.title_suffix'))

@section('content')
{{-- WIDTH FOLLOWS THE TAB, because these nine tabs are not one kind of
     screen. Three groups, and the old flat max-w-5xl was wrong for two of
     them:

       full     the inbox — a transcript beside a conversation list wants
                every pixel the shell has.
       wide     the tables: knowledge sources, catalogue rows, database
                columns, channels. A six-column table in a 1024px box wraps
                and then leaves a third of the page blank beside it.
       reading  the forms and the playground. A text field 1500px wide is
                unusable and a chat column that wide is unreadable, so these
                stay narrow and centred — narrow on purpose is not the same
                thing as empty by accident.

     Every cap sits inside the shell's own 1600px, so this only ever narrows.
     The transition makes switching tabs a resize rather than a jump. --}}
<div class="mx-auto w-full transition-[max-width] duration-base ease-out"
    {{-- A ternary chain rather than a class object, so an unrecognised tab
         falls through to the reading width instead of to no cap at all. --}}
    :class="tab === 'conversations'
        ? 'max-w-none'
        : (['knowledge', 'catalog', 'database', 'channels'].includes(tab) ? 'max-w-7xl' : 'max-w-4xl')"
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
    <a href="{{ route('agents.index') }}" class="section-link text-muted hover:text-brand-strong dark:text-night-muted">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
        {{ __('agents.show.back') }}
    </a>

    <div class="page-header mt-2">
        <div class="flex min-w-0 items-center gap-3">
            <div class="icon-circle shrink-0 text-lg font-medium" aria-hidden="true">
                {{ mb_strtoupper(mb_substr($agent->name, 0, 1)) }}
            </div>
            <div class="min-w-0">
                <h1 class="page-title">{{ $agent->name }}</h1>
                <p class="page-sub mt-1">{{ $agent->description ?: __('agents.show.no_description') }}</p>
            </div>
        </div>
        <div class="page-actions">
            {{-- Status as a badge rather than a word buried in the settings
                 tab: whether this assistant is answering customers right now
                 is the first thing anyone opening its page wants to know. --}}
            <span class="badge {{ $agent->status === 'active' ? 'badge-success' : 'badge-neutral' }}">
                <span class="h-1.5 w-1.5 rounded-full {{ $agent->status === 'active' ? 'bg-success' : 'bg-faint' }}" aria-hidden="true"></span>
                {{ $agent->status === 'active' ? __('agents.index.status_active') : __('agents.index.status_paused') }}
            </span>
        </div>
    </div>

    {{-- Tab nav, on the shared `.tabs` skin so it matches the leads page and
         the dashboard's period switch rather than being a third tab style. --}}
    <div class="tabs">
        @foreach (__('agents.show.tabs') as $key => $label)
            <button type="button" @click="tab = '{{ $key }}'"
                :class="tab === '{{ $key }}' && 'tab-active'"
                :aria-selected="tab === '{{ $key }}'"
                class="tab shrink-0">
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
        <div x-show="tab === 'database'" x-cloak x-transition.opacity.duration.200ms>
            @include('agents.partials.database-tab', ['agent' => $agent, 'database' => $database])
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
