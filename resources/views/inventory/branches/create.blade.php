@extends('layouts.app')

@section('title', 'New branch')

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Inventory</p>
        <h1 class="h3 mb-1">New branch</h1>
    </div>
    @include('inventory.partials.workspace-nav', ['active' => 'branches'])
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ route('inventory.branches.store') }}">
                @csrf
                @include('inventory.branches.partials.form')
                <div class="mt-4 d-flex gap-2">
                    <button class="btn btn-primary">Create branch</button>
                    <a href="{{ route('inventory.branches.index') }}" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
@endsection
