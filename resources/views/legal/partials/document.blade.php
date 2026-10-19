{{--
    Shared renderer for both legal pages. Expects:
    $doc        — 'terms' or 'privacy', the key under lang/*/legal.php
    $otherRoute — route name of the other legal page, for the cross-link below
    $otherLabel — its link text
--}}
@php
    $t = "legal.$doc";
@endphp

<div class="auth-intro">
    <span class="auth-kicker"><span class="auth-kicker-dot" aria-hidden="true"></span>{{ __('common.brand') }}</span>
    <h1 class="mt-4 font-display text-3xl font-semibold tracking-tight sm:text-4xl">{{ __("$t.title") }}</h1>
    <p class="mt-2 text-xs font-medium uppercase tracking-wide text-muted dark:text-night-muted">
        {{ __("$t.updated_label") }}: {{ __("$t.updated_at") }}
    </p>
</div>

<div class="mt-6 text-sm leading-7">
    <p class="text-ink dark:text-night-ink">{{ __("$t.intro") }}</p>

    @foreach (__("$t.sections") as $section)
        <h2 class="mt-7 text-base font-semibold text-ink dark:text-night-ink">{{ $section['heading'] }}</h2>
        @foreach ($section['body'] as $paragraph)
            <p class="mt-2 text-muted dark:text-night-muted">{{ $paragraph }}</p>
        @endforeach
    @endforeach
</div>

<p class="mt-8 border-t border-hairline pt-5 text-center text-sm text-muted dark:border-night-raised dark:text-night-muted">
    <a href="{{ route($otherRoute, ['locale' => app()->getLocale()]) }}" data-transition
        class="font-semibold text-brand-strong hover:underline">{{ $otherLabel }}</a>
</p>
