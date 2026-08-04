@extends('layouts.app')

@section('title', config('ui.service_case.plural'))

@section('content')
    @include('incidents.partials.index-listing', [
        'incidents' => $incidents,
        'categories' => $categories,
        'filters' => $filters,
        'embedded' => false,
    ])
@endsection

@push('vite')
    @vite('resources/js/pages/service-cases.js')
@endpush
