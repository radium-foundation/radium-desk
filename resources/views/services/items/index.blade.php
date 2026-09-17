@extends('layouts.app')

@section('title', 'Service items')

@section('content')
    <div class="mb-4 d-flex justify-content-between flex-wrap gap-2">
        <div>
            <p class="text-muted small text-uppercase fw-semibold mb-1">Services</p>
            <h1 class="h3 mb-1">Service master</h1>
            <p class="text-muted mb-0">Catalog for desk service sales. Not inventory products.</p>
        </div>
        @if($canManage)
            <a href="{{ route('services.items.create') }}" class="btn btn-primary">New service</a>
        @endif
    </div>

    @include('services.partials.workspace-nav', ['active' => 'items'])

    <form method="GET" class="row g-2 mb-3">
        <div class="col-md-4">
            <input type="search" name="q" class="form-control" value="{{ $filters['q'] ?? '' }}" placeholder="Code or name">
        </div>
        <div class="col-md-3">
            <select name="category_id" class="form-select">
                <option value="">All categories</option>
                @foreach($categories as $category)
                    <option value="{{ $category->id }}" @selected((int) ($filters['category_id'] ?? 0) === (int) $category->id)>{{ $category->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-auto"><button class="btn btn-outline-secondary">Filter</button></div>
    </form>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table mb-0 align-middle">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Category</th>
                        <th>SAC</th>
                        <th>GST</th>
                        <th>Ex-GST</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($items as $item)
                        <tr>
                            <td>{{ $item->code ?: '—' }}</td>
                            <td>{{ $item->name }}@if($item->duration_label)<div class="small text-muted">{{ $item->duration_label }}</div>@endif</td>
                            <td>{{ $item->category?->name }}</td>
                            <td>{{ $item->sac_code ?: '—' }}</td>
                            <td>{{ $item->gst_rate }}%</td>
                            <td>₹{{ number_format((float) $item->price_ex_gst, 2) }}</td>
                            <td><span class="badge {{ $item->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $item->is_active ? 'Active' : 'Inactive' }}</span></td>
                            <td class="text-end">
                                @if($canManage)
                                    <a href="{{ route('services.items.edit', $item) }}" class="btn btn-sm btn-outline-primary">Edit</a>
                                    <form method="POST" action="{{ route('services.items.toggle', $item) }}" class="d-inline">@csrf @method('PATCH')
                                        <button class="btn btn-sm btn-outline-secondary">{{ $item->is_active ? 'Deactivate' : 'Activate' }}</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-muted p-4">No service items yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3">{{ $items->links() }}</div>
@endsection
