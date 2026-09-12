@extends('layouts.app')

@section('title', 'Edit vendor')

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Purchasing</p>
        <h1 class="h3 mb-1">Edit {{ $vendor->business_name }}</h1>
    </div>

    @include('purchasing.partials.workspace-nav', ['active' => 'vendors'])

    <form method="POST" action="{{ route('purchasing.vendors.update', $vendor) }}" class="card border-0 shadow-sm p-4">
        @csrf
        @method('PUT')
        @include('purchasing.vendors._form', ['vendor' => $vendor])
        <button class="btn btn-primary">Save vendor</button>
    </form>
@endsection
