@extends('layouts.app')

@section('title', 'Adjustments')

@section('content')
    <div class="mb-4 d-flex justify-content-between flex-wrap gap-2">
        <div>
            <p class="text-muted small text-uppercase fw-semibold mb-1">Inventory</p>
            <h1 class="h3 mb-1">Adjustments</h1>
            <p class="text-muted mb-0">Audited count, damage, and write-off changes. Sales still go through POS.</p>
        </div>
        <a href="{{ route('inventory.adjustments.create') }}" class="btn btn-primary">New adjustment</a>
    </div>

    @include('inventory.partials.workspace-nav', ['active' => 'adjustments'])

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>Adjustment</th>
                        <th>Branch</th>
                        <th>Reason</th>
                        <th>By</th>
                        <th>When</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($adjustments as $adjustment)
                        <tr>
                            <td>{{ $adjustment->adjustment_no }}</td>
                            <td>{{ $adjustment->branch?->code }}</td>
                            <td>{{ $adjustment->reason->label() }}</td>
                            <td>{{ $adjustment->createdBy?->name }}</td>
                            <td>{{ $adjustment->created_at?->timezone(config('app.timezone'))->format('d M Y H:i') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-muted p-4">No adjustments yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3">{{ $adjustments->links() }}</div>
@endsection
