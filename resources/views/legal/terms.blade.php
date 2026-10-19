@php
    $seoTitle = __('legal.terms.title');
    $seoDescription = __('legal.terms.intro');
    $cardWidth = 'max-w-3xl';
@endphp
@extends('layouts.auth')

@section('card')
    @include('legal.partials.document', [
        'doc' => 'terms',
        'otherRoute' => 'legal.privacy',
        'otherLabel' => __('legal.nav.privacy_link'),
    ])
@endsection
