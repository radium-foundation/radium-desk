@extends('layouts.app')

@section('content')
<div class="container py-4">
    @include('navigation.control-and-admin-workspace-nav', ['active' => 'driver_management_radiumbox'])

    <h1 class="h4 mb-3">Driver management — RadiumBox</h1>
    <p class="text-muted small">
        @if ($mediaUploadEnabled ?? false)
            Authorized uploads are quarantined on the media host, then explicitly published to
            <code>media.radiumbox.com/public/drivers/</code>.
        @else
            Uploads are quarantined on RadiumBox, then explicitly published to the catalogue download path.
        @endif
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
                    @if ($mediaUploadEnabled ?? false)
                        <p class="small text-muted">Maximum file size: {{ number_format($maxRawBytes) }} bytes. Large uploads may fail if the network is slow (proxy timeout risk).</p>
                        <form id="media-driver-upload-form">
                            @csrf
                            <input type="hidden" name="product_driver_id" value="{{ $selected->id }}">
                            <input type="hidden" name="catalog_basename" value="{{ $selected->file }}">
                            <div class="mb-3">
                                <input type="file" name="file" id="media-driver-file" class="form-control" required>
                            </div>
                            <button type="submit" class="btn btn-primary" id="media-driver-upload-btn">Upload to media quarantine</button>
                            <span class="small text-muted ms-2" id="media-driver-upload-status" aria-live="polite"></span>
                        </form>
                        <form id="media-driver-complete-form" method="post" action="{{ route('driver-management.radiumbox.complete-media') }}" class="d-none">
                            @csrf
                            <input type="hidden" name="product_driver_id" value="{{ $selected->id }}">
                            <input type="hidden" name="upload_id" id="media-complete-upload-id">
                            <input type="hidden" name="sha256" id="media-complete-sha256">
                            <input type="hidden" name="bytes" id="media-complete-bytes">
                        </form>
                    @else
                        <form method="post" action="{{ route('driver-management.radiumbox.store') }}" enctype="multipart/form-data">
                            @csrf
                            <input type="hidden" name="product_driver_id" value="{{ $selected->id }}">
                            <div class="mb-3">
                                <input type="file" name="file" class="form-control" required>
                            </div>
                            <button type="submit" class="btn btn-primary">Upload to quarantine</button>
                        </form>
                    @endif
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
                            @if (! empty($pending['sha256']))
                                <input type="hidden" name="sha256" value="{{ $pending['sha256'] }}">
                            @endif
                            <button type="submit" class="btn btn-warning">Publish to public download path</button>
                        </form>
                    </div>
                </div>
            @endif
        @endif
    @endif
</div>

@if (($configured ?? false) && ($mediaUploadEnabled ?? false) && ($selected ?? null))
<script>
(function () {
    const form = document.getElementById('media-driver-upload-form');
    const fileInput = document.getElementById('media-driver-file');
    const statusEl = document.getElementById('media-driver-upload-status');
    const btn = document.getElementById('media-driver-upload-btn');
    const maxBytes = {{ (int) ($maxRawBytes ?? 99000000) }};
    const authorizeUrl = @json(route('driver-management.radiumbox.authorize-media'));
    const csrf = @json(csrf_token());

    form?.addEventListener('submit', async function (e) {
        e.preventDefault();
        const file = fileInput?.files?.[0];
        if (!file) {
            statusEl.textContent = 'Select a file.';
            return;
        }
        if (file.size > maxBytes) {
            statusEl.textContent = 'File exceeds the maximum allowed size.';
            return;
        }
        btn.disabled = true;
        statusEl.textContent = 'Requesting authorization…';
        try {
            const authRes = await fetch(authorizeUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                },
                body: JSON.stringify({
                    product_driver_id: Number(form.product_driver_id.value),
                }),
            });
            const auth = await authRes.json();
            if (!authRes.ok) {
                throw new Error(auth.message || 'Authorization failed.');
            }
            const expectedBase = String(form.catalog_basename.value || '');
            if (file.name !== expectedBase) {
                throw new Error('File name must match the catalogue file name.');
            }
            statusEl.textContent = 'Uploading to media host…';
            const body = new FormData();
            body.append('file', file);
            const uploadRes = await fetch(auth.media_quarantine_url, {
                method: 'POST',
                headers: {
                    'Authorization': 'Bearer ' + auth.grant_token,
                    'Accept': 'application/json',
                },
                body,
            });
            const upload = await uploadRes.json();
            if (!uploadRes.ok) {
                throw new Error(upload.message || 'Media upload failed.');
            }
            document.getElementById('media-complete-upload-id').value = upload.upload_id;
            document.getElementById('media-complete-sha256').value = upload.sha256;
            document.getElementById('media-complete-bytes').value = upload.bytes;
            statusEl.textContent = 'Quarantine complete. Saving…';
            document.getElementById('media-driver-complete-form').submit();
        } catch (err) {
            statusEl.textContent = err.message || 'Upload failed.';
            btn.disabled = false;
        }
    });
})();
</script>
@endif
@endsection
