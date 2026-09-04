@extends('layouts.app')

@section('title', 'Branches')

@section('content')
    <div class="mb-4 d-flex justify-content-between flex-wrap gap-2">
        <div>
            <p class="text-muted small text-uppercase fw-semibold mb-1">Inventory</p>
            <h1 class="h3 mb-1">Branches</h1>
            <p class="text-muted mb-0">Stock is always attributable to a branch. Existing stock is never moved silently.</p>
        </div>
        <a href="{{ route('inventory.branches.create') }}" class="btn btn-primary">New branch</a>
    </div>
    @include('inventory.partials.workspace-nav', ['active' => 'branches'])
    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Name</th>
                        <th>GSTIN</th>
                        <th>Active</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($branches as $branch)
                        <tr>
                            <td>{{ $branch->code }}</td>
                            <td>{{ $branch->name }}</td>
                            <td>{{ $branch->gstin ?: '—' }}</td>
                            <td>{{ $branch->is_active ? 'Yes' : 'No' }}</td>
                            <td><a href="{{ route('inventory.branches.edit', $branch) }}">Edit</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-muted p-4">Create a branch before receiving stock.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3">{{ $branches->links() }}</div>
@endsection
