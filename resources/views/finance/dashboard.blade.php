@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Finance</p>
        <h1 class="h3 mb-1">Dashboard</h1>
        <p class="text-muted mb-0">Operational finance overview. Values are placeholders until ledger posting ships.</p>
    </div>

    @include('finance.partials.workspace-nav', ['active' => 'dashboard'])

    <div class="row g-3 mb-4">
        @foreach($widgets as $widget)
            <div class="col-12 col-sm-6 col-xl">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="text-muted small mb-1">{{ $widget['label'] }}</div>
                        <div class="fs-4 fw-semibold">{{ $widget['value'] }}</div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    @include('finance.partials.placeholder', [
        'message' => 'Finance dashboard widgets are static placeholders. No live balances or queries in Phase 1.',
    ])
@endsection
