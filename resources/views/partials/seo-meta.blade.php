@php
    $__segments = request()->segments();
    $__localizedUrls = collect(['ru', 'tg', 'en'])->mapWithKeys(function (string $loc) use ($__segments) {
        $segs = $__segments;
        $segs[0] = $loc;
        return [$loc => url('/'.implode('/', $segs))];
    });
    $__currentUrl = $__localizedUrls[app()->getLocale()] ?? url('/'.app()->getLocale());
@endphp

<title>{{ $seoTitle }}</title>
<meta name="description" content="{{ $seoDescription }}" />

<link rel="canonical" href="{{ $__currentUrl }}" />
@foreach ($__localizedUrls as $loc => $localizedUrl)
    <link rel="alternate" hreflang="{{ $loc }}" href="{{ $localizedUrl }}" />
@endforeach
<link rel="alternate" hreflang="x-default" href="{{ $__localizedUrls['ru'] }}" />

<meta property="og:type" content="website" />
<meta property="og:site_name" content="{{ __('common.brand') }}" />
<meta property="og:title" content="{{ $seoTitle }}" />
<meta property="og:description" content="{{ $seoDescription }}" />
<meta property="og:url" content="{{ $__currentUrl }}" />
<meta property="og:image" content="{{ asset('images/brand/gen/og-image.png') }}" />
<meta property="og:locale" content="{{ app()->getLocale() }}" />
<meta name="twitter:card" content="summary_large_image" />

@include('partials.favicons')
