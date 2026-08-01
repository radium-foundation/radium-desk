@extends('layouts.app')

@section('title', 'Vendor Payments')

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Finance</p>
        <h1 class="h3 mb-1">Vendor Payments</h1>
        <p class="text-muted mb-0">Vendor payout records will live here.</p>
    </div>

    @include('finance.partials.workspace-nav', ['active' => 'vendor_payments'])

    @include('finance.partials.placeholder')
@endsection
