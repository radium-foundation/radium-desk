@extends('layouts.app')

@section('title', 'Bank Ledger')

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Finance</p>
        <h1 class="h3 mb-1">Bank Ledger</h1>
        <p class="text-muted mb-0">Bank account movement will live here.</p>
    </div>

    @include('finance.partials.workspace-nav', ['active' => 'bank'])

    @include('finance.partials.placeholder')
@endsection
