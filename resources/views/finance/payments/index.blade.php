@extends('layouts.app')

@section('title', 'Customer Payments')

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Finance</p>
        <h1 class="h3 mb-1">Customer Payments</h1>
        <p class="text-muted mb-0">Inbound customer payment records will live here.</p>
    </div>

    @include('finance.partials.workspace-nav', ['active' => 'payments'])

    @include('finance.partials.placeholder')
@endsection
