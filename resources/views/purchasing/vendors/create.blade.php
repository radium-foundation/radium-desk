@extends('layouts.app')

@section('title', 'New vendor')

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Purchasing</p>
        <h1 class="h3 mb-1">New vendor</h1>
    </div>

    @include('purchasing.partials.workspace-nav', ['active' => 'vendors'])

    <form method="POST" action="{{ route('purchasing.vendors.store') }}" class="card border-0 shadow-sm p-4">
        @csrf
        @include('purchasing.vendors._form')
        <button class="btn btn-primary">Create vendor</button>
    </form>
@endsection
