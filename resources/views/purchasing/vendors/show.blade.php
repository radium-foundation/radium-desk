@extends('layouts.app')

@section('title', $vendor->business_name)

@section('content')
    <div class="mb-4 d-flex justify-content-between flex-wrap gap-2">
        <div>
            <p class="text-muted small text-uppercase fw-semibold mb-1">Purchasing</p>
            <h1 class="h3 mb-1">{{ $vendor->business_name }}</h1>
            <p class="text-muted mb-0">{{ $vendor->is_active ? 'Active' : 'Inactive' }}</p>
        </div>
        <div class="d-flex gap-2">
            @can(\Database\Seeders\RolePermissionSeeder::PERMISSION_PURCHASE_VENDOR_MANAGE)
                <a href="{{ route('purchasing.vendors.edit', $vendor) }}" class="btn btn-outline-primary">Edit</a>
                <form method="POST" action="{{ route('purchasing.vendors.toggle', $vendor) }}">
                    @csrf
                    <button class="btn btn-outline-secondary">{{ $vendor->is_active ? 'Deactivate' : 'Activate' }}</button>
                </form>
            @endcan
        </div>
    </div>

    @include('purchasing.partials.workspace-nav', ['active' => 'vendors'])

    <div class="card border-0 shadow-sm p-4">
        <dl class="row mb-0">
            <dt class="col-sm-3">Legal name</dt><dd class="col-sm-9">{{ $vendor->legal_name ?? '—' }}</dd>
            <dt class="col-sm-3">GSTIN</dt><dd class="col-sm-9">{{ $vendor->gstin ?? '—' }}</dd>
            <dt class="col-sm-3">PAN</dt><dd class="col-sm-9">{{ $vendor->pan ?? '—' }}</dd>
            <dt class="col-sm-3">Phone</dt><dd class="col-sm-9">{{ $vendor->phone ?? '—' }}</dd>
            <dt class="col-sm-3">Email</dt><dd class="col-sm-9">{{ $vendor->email ?? '—' }}</dd>
            <dt class="col-sm-3">Address</dt><dd class="col-sm-9">{{ $vendor->billing_address ?? '—' }}</dd>
            <dt class="col-sm-3">City / State / PIN</dt><dd class="col-sm-9">{{ collect([$vendor->city, $vendor->state, $vendor->pin])->filter()->implode(', ') ?: '—' }}</dd>
            <dt class="col-sm-3">Legacy provenance</dt>
            <dd class="col-sm-9">
                @if($vendor->legacy_supplier_id)
                    {{ $vendor->legacy_source_database }}.{{ $vendor->legacy_source_table }} #{{ $vendor->legacy_supplier_id }}
                    @if($vendor->legacy_import_batch)
                        <br><span class="text-muted small">Batch {{ $vendor->legacy_import_batch }} @ {{ $vendor->legacy_imported_at?->format('Y-m-d H:i') }}</span>
                    @endif
                @else
                    —
                @endif
            </dd>
            <dt class="col-sm-3">Notes</dt><dd class="col-sm-9">{{ $vendor->notes ?? '—' }}</dd>
        </dl>
    </div>
@endsection
