@extends('layouts.dashboard')

@section('title', __('settings.team.heading'))

@section('content')
<div class="mx-auto max-w-5xl space-y-6">
    <div class="page-header">
        <div>
            <h1 class="page-title">{{ __('settings.team.heading') }}</h1>
            <p class="page-sub">{{ __('settings.team.section_description') }}</p>
        </div>
    </div>

    @if (! $activeTeam || $activeTeam->is_personal)
        <div class="card card-pad">
            <span class="badge badge-neutral">{{ __('settings.team.personal_badge') }}</span>
            <h2 class="mt-4 font-display text-xl font-semibold">{{ __('settings.team.solo_heading') }}</h2>
            <p class="mt-2 max-w-2xl text-sm text-muted dark:text-night-muted">{{ __('settings.team.solo_description') }}</p>
        </div>

        <div class="card card-pad">
            <h2 class="font-display text-lg font-semibold">{{ __('settings.team.create_heading') }}</h2>
            <p class="mt-1 text-sm text-muted dark:text-night-muted">{{ __('settings.team.create_description') }}</p>
            @include('teams.partials.create-form')
        </div>
    @else
        <div class="card card-pad">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-muted dark:text-night-muted">{{ __('settings.team.active_label') }}</p>
                    <h2 class="mt-1 font-display text-xl font-semibold">{{ $activeTeam->name }}</h2>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    @can('team.roles.view')
                        <a href="{{ route('teams.roles.index') }}" class="btn btn-secondary">{{ __('settings.roles.manage_button') }}</a>
                    @endcan
                    {{-- G-01: the Owner is immune (same rule leave() itself
                         enforces, and the only one not already true in
                         this branch — is_personal is already excluded by
                         the outer @if above). Same lightweight confirm()
                         the member-removal button uses; no new component. --}}
                    @if (! auth()->user()->isOwnerOf($activeTeam->id))
                        <form method="POST" action="{{ route('teams.leave') }}"
                            x-on:submit="if (! window.confirm(window.__i18n.messages[window.__i18n.current].settings.team.leave_confirm)) $event.preventDefault()">
                            @csrf
                            {{-- The team THIS PAGE was rendered for, not whatever
                                 is active by the time the click lands — a stale
                                 tab left open while another tab switches teams
                                 must not leave the wrong one. leave() 409s if
                                 this no longer matches activeTeamId(). --}}
                            <input type="hidden" name="team_id" value="{{ $activeTeam->id }}" />
                            <button type="submit" class="btn btn-ghost text-danger dark:text-danger-light" data-i18n="settings.team.leave_button">
                                {{ __('settings.team.leave_button') }}
                            </button>
                        </form>
                    @endif
                </div>
            </div>
        </div>

        @include('teams.partials.management')

        <details class="card card-pad">
            <summary class="cursor-pointer text-sm font-semibold">{{ __('settings.team.create_another') }}</summary>
            <p class="mt-3 text-sm text-muted dark:text-night-muted">{{ __('settings.team.create_another_description') }}</p>
            @include('teams.partials.create-form')
        </details>
    @endif
</div>
@endsection
