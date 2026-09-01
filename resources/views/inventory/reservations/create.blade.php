@extends('layouts.app')

@section('title', 'Reserve serials')

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Inventory</p>
        <h1 class="h3 mb-1">Reserve serials</h1>
    </div>

    @include('inventory.partials.workspace-nav', ['active' => 'reservations'])

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ route('inventory.reservations.store') }}">
                @csrf
                <div class="mb-3">
                    <label class="form-label">Branch</label>
                    <select name="branch_id" class="form-select" required>
                        <option value="">Select</option>
                        @foreach($branches as $branch)
                            <option value="{{ $branch->id }}">{{ $branch->code }} — {{ $branch->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Serials</label>
                    <textarea name="serials" class="form-control" rows="5" required>{{ old('serials') }}</textarea>
                    @error('serials')<div class="text-danger small">{{ $message }}</div>@enderror
                </div>
                <div class="mb-3">
                    <label class="form-label">Notes</label>
                    <input type="text" name="notes" class="form-control" value="{{ old('notes') }}">
                </div>
                <button class="btn btn-primary">Reserve</button>
            </form>
        </div>
    </div>
@endsection
