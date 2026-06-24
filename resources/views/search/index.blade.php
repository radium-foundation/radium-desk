@extends('layouts.app')

@section('title', 'Search')

@section('content')
    <div class="mb-4">
        <h1 class="h3 mb-1">Global Search</h1>
        <p class="text-muted mb-0">
            Search by order ID, serial number, transaction ID, or {{ strtolower(config('ui.service_case.reference_label')) }}.
        </p>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form action="{{ route('search.index') }}" method="GET" class="row g-2">
                <div class="col-md-10">
                    <input
                        type="search"
                        name="q"
                        class="form-control form-control-lg"
                        placeholder="Search orders, service cases, approvals, refunds..."
                        value="{{ $query }}"
                        autofocus
                    >
                </div>
                <div class="col-md-2 d-grid">
                    <button type="submit" class="btn btn-primary btn-lg">Search</button>
                </div>
            </form>
        </div>
    </div>

    @if($query === '')
        <div class="alert alert-info mb-0">
            Enter an order ID, serial number, or transaction ID to begin searching.
        </div>
    @elseif($totalResults === 0)
        <div class="alert alert-warning mb-0">
            No results found for <strong>{{ $query }}</strong>.
        </div>
    @else
        <p class="text-muted mb-4">
            {{ number_format($totalResults) }} {{ Str::plural('result', $totalResults) }} for
            <strong>{{ $query }}</strong>
        </p>

        <div class="row g-4">
            @if($results['orders'])
                @include('search.partials.group', [
                    'title' => 'Orders',
                    'paginator' => $results['orders'],
                    'emptyMessage' => null,
                ])
            @endif

            @if($results['incidents'])
                @include('search.partials.group', [
                    'title' => config('ui.service_case.plural'),
                    'paginator' => $results['incidents'],
                    'emptyMessage' => null,
                ])
            @endif

            @if($results['approvals'])
                @include('search.partials.group', [
                    'title' => 'Approvals',
                    'paginator' => $results['approvals'],
                    'emptyMessage' => null,
                ])
            @endif

            @if($results['refunds'])
                @include('search.partials.group', [
                    'title' => 'Refunds',
                    'paginator' => $results['refunds'],
                    'emptyMessage' => null,
                ])
            @endif
        </div>
    @endif
@endsection
