@extends('layouts.dashboard')

@section('title', __('conversations.index.title'))

@section('content')
{{--
    The global inbox: every conversation across every agent.

    The inbox itself lives in a partial, because an agent's own Диалоги tab
    renders exactly the same thing pre-scoped to that agent. Only the page
    heading is specific to this route.
--}}
{{-- The width cap lives in layouts/dashboard now, once, for every page — and
     the inbox is the page that most wants all of it. --}}
<div>
    <div class="page-header">
        <div>
            <h1 class="page-title" data-i18n="conversations.index.heading">{{ __('conversations.index.heading') }}</h1>
            <p class="page-sub" data-i18n="conversations.index.subheading">{{ __('conversations.index.subheading') }}</p>
        </div>
    </div>

    @include('conversations.partials.inbox', [
        'scopeAgent' => null,
        'agents' => $agents,
        'channelTypes' => $channelTypes,
        'filters' => $filters,
    ])
</div>
@endsection
