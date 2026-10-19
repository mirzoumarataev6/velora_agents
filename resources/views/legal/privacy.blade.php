@php
    $seoTitle = __('legal.privacy.title');
    $seoDescription = __('legal.privacy.intro');
    $cardWidth = 'max-w-3xl';
@endphp
@extends('layouts.auth')

@section('card')
    @include('legal.partials.document', [
        'doc' => 'privacy',
        'otherRoute' => 'legal.terms',
        'otherLabel' => __('legal.nav.terms_link'),
    ])
@endsection
