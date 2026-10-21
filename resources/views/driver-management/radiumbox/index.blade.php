@extends('layouts.app')

@section('content')
<div class="container py-4">
    @include('navigation.control-and-admin-workspace-nav', ['active' => 'driver_management_radiumbox'])

    <h1 class="h4 mb-3">Driver management — RadiumBox</h1>
    <p class="text-muted small">
        Uploads are quarantined on RadiumBox, then explicitly published to the catalogue download path.
        Publishing verifies checksum integrity only; it does <strong>not</strong> prove OEM authenticity.
    </p>

    @if (! $configured)
        <div class="alert alert-warning">
            RadiumBox driver management is not configured on this Desk environment
            (<code>RADIUMBOX_DRIVER_MANAGEMENT_ENABLED</code> and API token).
        </div>
    @else
        @if (session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="get" class="mb-4">
            <label class="form-label" for="product_driver_id">Catalogue driver row</label>
            <select name="product_driver_id" id="product_driver_id" class="form-select" onchange="this.form.submit()">
                <option value="">Select…</option>
                @foreach ($drivers as $driver)
                    <option value="{{ $driver->id }}" @selected(optional($selected)->id === $driver->id)>
                        #{{ $driver->id }} — {{ $driver->title }} ({{ $driver->file }})
                    </option>
                @endforeach
            </select>
        </form>

        @if ($selected)
            <div class="card mb-4">
                <div class="card-body">
                    <h2 class="h6">Upload to quarantine</h2>
                    <form method="post" action="{{ route('driver-management.radiumbox.store') }}" enctype="multipart/form-data">
                        @csrf
                        <input type="hidden" name="product_driver_id" value="{{ $selected->id }}">
                        <div class="mb-3">
                            <input type="file" name="file" class="form-control" required>
                        </div>
                        <button type="submit" class="btn btn-primary">Upload to quarantine</button>
                    </form>
                </div>
            </div>

            @if ($pending)
                <div class="card border-warning">
                    <div class="card-body">
                        <h2 class="h6">Publish (explicit)</h2>
                        <p class="small mb-2">Upload ID: <code>{{ $pending['upload_id'] }}</code></p>
                        <p class="small mb-2">SHA-256: <code>{{ $pending['sha256'] }}</code></p>
                        <form method="post" action="{{ route('driver-management.radiumbox.publish') }}">
                            @csrf
                            <input type="hidden" name="upload_id" value="{{ $pending['upload_id'] }}">
                            <input type="hidden" name="product_driver_id" value="{{ $selected->id }}">
                            <button type="submit" class="btn btn-warning">Publish to public download path</button>
                        </form>
                    </div>
                </div>
            @endif
        @endif
    @endif
</div>
@endsection
