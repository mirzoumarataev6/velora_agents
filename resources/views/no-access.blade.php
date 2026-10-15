@php
    $seoTitle = __('common.no_access.title');

    // D-03: the same snapshot the sidebar's team switcher reads
    // (partials/team-switcher.blade.php) — one membership query per
    // request either way, never a second one just for this page. Only the
    // OTHER teams: the current one is exactly why this page is being
    // shown, so offering to "switch" to it would be a no-op at best.
    $__otherTeamMemberships = auth()->user()->cachedTeamMemberships()
        ->reject(fn ($membership) => (int) $membership->team_id === (int) auth()->user()->current_team_id);
@endphp
@extends('layouts.auth')

@section('card')
    <h1 class="text-center font-display text-2xl font-semibold" data-i18n="common.no_access.title">{{ __('common.no_access.title') }}</h1>
    <p class="mt-3 text-center text-sm text-muted dark:text-night-muted" data-i18n="common.no_access.message">{{ __('common.no_access.message') }}</p>

    {{-- The dead end this page used to be for anyone with a second team: no
         way off it short of signing out, which doesn't even change
         current_team_id, so signing back in returns here again. A role
         with no access in THIS team may hold one in another — switching
         is exactly as safe here as it is anywhere else in the product
         (TeamSwitchController's own membership check still runs), so it
         is offered the same way the sidebar does, minus the sidebar. --}}
    @if ($__otherTeamMemberships->isNotEmpty())
        <div class="mt-6">
            <p class="text-center text-xs font-medium uppercase tracking-wide text-muted dark:text-night-muted" data-i18n="common.no_access.switch_heading">
                {{ __('common.no_access.switch_heading') }}
            </p>
            <div class="card card-pad mt-2 space-y-2">
                @foreach ($__otherTeamMemberships as $membership)
                    <form method="POST" action="{{ route('teams.switch') }}">
                        @csrf
                        <input type="hidden" name="team_id" value="{{ $membership->team_id }}" />
                        <button type="submit" class="btn btn-ghost block w-full text-center">
                            {{ $membership->team_is_personal ? __('settings.team.personal_badge') : $membership->team_name }}
                        </button>
                    </form>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Not a link back into the app: every destination HomeDestination
         knows how to try already failed, and /dashboard's own 403 is the
         one outcome this page exists to avoid repeating. Signing out is
         the one action that is always safe to offer here. --}}
    <form method="POST" action="{{ route('logout') }}" class="mt-6">
        @csrf
        <button type="submit" class="btn btn-primary block w-full text-center" data-i18n="common.no_access.sign_out">
            {{ __('common.no_access.sign_out') }}
        </button>
    </form>
@endsection
