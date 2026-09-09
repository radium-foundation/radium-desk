@extends('layouts.app')

@section('title', 'New Party')

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Finance</p>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-2">
                <li class="breadcrumb-item"><a href="{{ route('finance.parties.index') }}">Parties</a></li>
                <li class="breadcrumb-item active" aria-current="page">New</li>
            </ol>
        </nav>
        <h1 class="h3 mb-1">New Party</h1>
                <p class="text-muted mb-0">One legal entity can be a customer, a vendor, or both. POS till customers stay on inventory_customers.</p>
    </div>

    @include('finance.partials.workspace-nav', ['active' => 'parties'])

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ route('finance.parties.store') }}" class="row g-3">
                @csrf
                <div class="col-md-6">
                    <label for="legal_name" class="form-label">Legal name</label>
                    <input type="text" id="legal_name" name="legal_name" class="form-control" value="{{ old('legal_name') }}" required>
                </div>
                <div class="col-md-6">
                    <label for="trade_name" class="form-label">Trade name</label>
                    <input type="text" id="trade_name" name="trade_name" class="form-control" value="{{ old('trade_name') }}">
                </div>
                <div class="col-md-4">
                    <label for="kind" class="form-label">Type</label>
                    <select id="kind" name="kind" class="form-select" required>
                        @foreach($kinds as $kind)
                            <option value="{{ $kind->value }}" @selected(old('kind', 'organisation') === $kind->value)>{{ $kind->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label for="phone" class="form-label">Phone</label>
                    <input type="text" id="phone" name="phone" class="form-control" value="{{ old('phone') }}">
                </div>
                <div class="col-md-4">
                    <label for="email" class="form-label">Email</label>
                    <input type="email" id="email" name="email" class="form-control" value="{{ old('email') }}">
                </div>
                <div class="col-12">
                    <div class="form-label">Roles</div>
                    @foreach($roleTypes as $role)
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="checkbox" name="roles[]" id="role_{{ $role->value }}" value="{{ $role->value }}" @checked(in_array($role->value, old('roles', ['customer']), true))>
                            <label class="form-check-label" for="role_{{ $role->value }}">{{ $role->label() }}</label>
                        </div>
                    @endforeach
                </div>
                <div class="col-12">
                    <label for="notes" class="form-label">Notes</label>
                    <textarea id="notes" name="notes" class="form-control" rows="2">{{ old('notes') }}</textarea>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary">Create party</button>
                    <a href="{{ route('finance.parties.index') }}" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
@endsection
