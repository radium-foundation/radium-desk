@extends('layouts.app')

@section('title', 'Refunds')

@section('content')
    @include('refunds.partials.index-listing', [
        'refunds' => $refunds,
        'requesters' => $requesters,
        'queueCounts' => $queueCounts,
        'activeQueue' => $activeQueue,
        'filters' => $filters,
        'embedded' => false,
    ])
@endsection

@push('vite')
    @vite('resources/js/pages/refunds.js')
@endpush
