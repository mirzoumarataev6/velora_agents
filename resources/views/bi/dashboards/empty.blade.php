@extends('layouts.dashboard')

@section('title', __('bi.title'))

@section('content')
<div class="page-header">
    <div><h1 class="page-title">{{ __('bi.title') }}</h1></div>
</div>
{{-- The blank first dashboard is a product introduction, not an empty report.
     One statement and two real next steps are enough; repeating the page title
     and instruction in a second white rectangle made this look unfinished. --}}
<div class="bi-start-hero card mt-6 overflow-hidden px-6 py-8 sm:px-10 sm:py-10">
    <div class="relative z-10 max-w-2xl">
        <span class="text-overline text-brand-strong dark:text-brand-light">{{ __('bi.title') }}</span>
        <h2 class="mt-3 text-2xl font-semibold tracking-tight sm:text-3xl">{{ __('bi.analysis.empty_title') }}</h2>
        <p class="mt-3 max-w-xl text-sm leading-relaxed text-muted dark:text-night-muted">{{ __('bi.analysis.empty_body') }}</p>
    </div>
    <div class="bi-start-trace" aria-hidden="true"><span></span><span></span><span></span></div>
</div>
@include('bi.partials.analysis-start')
@endsection
