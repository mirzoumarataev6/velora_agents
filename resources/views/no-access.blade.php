@php
    $seoTitle = __('common.no_access.title');
@endphp
@extends('layouts.auth')

@section('card')
    <h1 class="text-center font-display text-2xl font-semibold" data-i18n="common.no_access.title">{{ __('common.no_access.title') }}</h1>
    <p class="mt-3 text-center text-sm text-muted dark:text-night-muted" data-i18n="common.no_access.message">{{ __('common.no_access.message') }}</p>

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
