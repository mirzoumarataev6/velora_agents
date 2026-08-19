@extends('layouts.dashboard')

@section('title', __('conversations.index.title'))

@section('content')
{{--
    The global inbox: every conversation across every agent.

    The inbox itself lives in a partial, because an agent's own Диалоги tab
    renders exactly the same thing pre-scoped to that agent. Only the page
    heading is specific to this route.
--}}
<div class="mx-auto max-w-7xl">
    <div>
        <h1 class="font-display text-2xl font-semibold" data-i18n="conversations.index.heading">{{ __('conversations.index.heading') }}</h1>
        <p class="mt-1 text-sm text-muted dark:text-night-muted" data-i18n="conversations.index.subheading">{{ __('conversations.index.subheading') }}</p>
    </div>

    @include('conversations.partials.inbox', [
        'scopeAgent' => null,
        'agents' => $agents,
        'channelTypes' => $channelTypes,
        'filters' => $filters,
    ])
</div>
@endsection
