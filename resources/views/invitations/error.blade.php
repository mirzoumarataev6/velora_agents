@php
    $seoTitle = __('settings.team.accept_error_title');
@endphp
@extends('layouts.auth')

@section('card')
    <h1 class="text-center font-display text-2xl font-semibold" data-i18n="settings.team.accept_error_title">{{ __('settings.team.accept_error_title') }}</h1>
    <p class="mt-3 text-center text-sm text-muted dark:text-night-muted">{{ $message }}</p>

    <a href="{{ route('home') }}" class="btn btn-primary mt-6 block text-center" data-i18n="settings.team.accept_error_back">
        {{ __('settings.team.accept_error_back') }}
    </a>
@endsection
