@extends('layouts.app')

@section('title', 'Vendors')

@section('content')
    <div class="mb-4 d-flex justify-content-between flex-wrap gap-2">
        <div>
            <p class="text-muted small text-uppercase fw-semibold mb-1">Purchasing</p>
            <h1 class="h3 mb-1">Vendors</h1>
        </div>
        @can(\Database\Seeders\RolePermissionSeeder::PERMISSION_PURCHASE_VENDOR_MANAGE)
            <a href="{{ route('purchasing.vendors.create') }}" class="btn btn-primary">New vendor</a>
        @endcan
    </div>

    @include('purchasing.partials.workspace-nav', ['active' => 'vendors'])

    <form method="GET" class="row g-2 mb-3">
        <div class="col-md-4">
            <input type="text" name="q" class="form-control" value="{{ $filters['q'] ?? '' }}" placeholder="Name, GSTIN, PAN, phone, legacy ID">
        </div>
        <div class="col-md-2">
            <select name="status" class="form-select">
                <option value="">All statuses</option>
                <option value="active" @selected(($filters['status'] ?? '') === 'active')>Active</option>
                <option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>Inactive</option>
            </select>
        </div>
        <div class="col-auto"><button class="btn btn-outline-secondary">Search</button></div>
    </form>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>GSTIN</th>
                        <th>PAN</th>
                        <th>Phone</th>
                        <th>Legacy ID</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($vendors as $vendor)
                        <tr>
                            <td>{{ $vendor->business_name }}</td>
                            <td>{{ $vendor->gstin ?? '—' }}</td>
                            <td>{{ $vendor->pan ?? '—' }}</td>
                            <td>{{ $vendor->phone ?? '—' }}</td>
                            <td>{{ $vendor->legacy_supplier_id ?? '—' }}</td>
                            <td>{{ $vendor->is_active ? 'Active' : 'Inactive' }}</td>
                            <td><a href="{{ route('purchasing.vendors.show', $vendor) }}">View</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-muted p-4">No vendors yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $vendors->links() }}</div>
@endsection
