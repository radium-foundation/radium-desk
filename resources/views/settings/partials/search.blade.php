<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3">
        <h2 class="h6 mb-0">Search Fields</h2>
    </div>
    <div class="card-body">
        <form method="POST" action="{{ route('settings.search.update') }}">
            @csrf
            @method('PUT')
            <p class="text-muted small">Choose which order fields global search may match. Future API integrations will respect these toggles.</p>
            <div class="vstack gap-3">
                <div class="form-check form-switch">
                    <input type="checkbox" name="order_id_enabled" value="1" id="order_id_enabled" class="form-check-input"
                           @checked(old('order_id_enabled', $search['order_id_enabled']))>
                    <label class="form-check-label" for="order_id_enabled">Order ID</label>
                </div>
                <div class="form-check form-switch">
                    <input type="checkbox" name="serial_number_enabled" value="1" id="serial_number_enabled" class="form-check-input"
                           @checked(old('serial_number_enabled', $search['serial_number_enabled']))>
                    <label class="form-check-label" for="serial_number_enabled">Serial Number</label>
                </div>
                <div class="form-check form-switch">
                    <input type="checkbox" name="transaction_id_enabled" value="1" id="transaction_id_enabled" class="form-check-input"
                           @checked(old('transaction_id_enabled', $search['transaction_id_enabled']))>
                    <label class="form-check-label" for="transaction_id_enabled">Service Reference</label>
                </div>
                <div class="form-check form-switch">
                    <input type="checkbox" name="email_enabled" value="1" id="email_enabled" class="form-check-input"
                           @checked(old('email_enabled', $search['email_enabled']))>
                    <label class="form-check-label" for="email_enabled">Email</label>
                </div>
                <div class="form-check form-switch">
                    <input type="checkbox" name="mobile_enabled" value="1" id="mobile_enabled" class="form-check-input"
                           @checked(old('mobile_enabled', $search['mobile_enabled']))>
                    <label class="form-check-label" for="mobile_enabled">Mobile</label>
                </div>
            </div>
            <div class="mt-4">
                <button type="submit" class="btn btn-primary">Save Search Settings</button>
            </div>
        </form>
    </div>
</div>
